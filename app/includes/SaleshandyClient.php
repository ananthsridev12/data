<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/WaveAssigner.php';

/**
 * Thin cURL wrapper around Saleshandy's REST API. No SDK/Composer, matching
 * the rest of this app.
 *
 * Endpoint paths, headers, and base URL below are taken directly from the
 * source of Saleshandy's own official open-source CLI
 * (@saleshandy/saleshandy-cli on npm, https://github.com/saleshandy/saleshandy-cli),
 * not guessed -- that package's dist/lib/api-client.js has the header set
 * (x-api-key, sh-application, Content-Type sent unconditionally) and
 * dist/lib/base-command.js has the base URL; each dist/commands/**\/*.js
 * file has its endpoint path and request shape. Re-check that package
 * (`npm pack @saleshandy/saleshandy-cli`) if a call here ever drifts from
 * what Saleshandy's API actually expects.
 */
class SaleshandyClient
{
    private const BASE_URL = 'https://open-api.saleshandy.com/api/open-api/v1';

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public static function fromConfig(array $config): self
    {
        $apiKey = $config['saleshandy']['api_key'] ?? '';
        if ($apiKey === '') {
            throw new SaleshandyApiException('Saleshandy API key is not configured (app/config/config.php -> saleshandy.api_key).');
        }
        return new self($apiKey);
    }

    /**
     * Every sequence, paginated through in full -- the campaign linking
     * screen and the live-status badges on Campaigns both need the
     * complete list, not just the first page.
     *
     * @return array<int,array{id:string,title:string,active:bool}>
     */
    public function listSequences(): array
    {
        $sequences = [];
        $page = 1;
        $pageSize = 1000; // API max per the CLI's own default flags
        do {
            $data = $this->request('GET', '/sequences', ['pageSize' => $pageSize, 'page' => $page, 'sort' => 'ASC', 'sortBy' => 'sequence.title']);
            $pageRows = $this->unwrap($data);
            $sequences = array_merge($sequences, $pageRows);
            $page++;
        } while (count($pageRows) === $pageSize);

        return $sequences;
    }

    /** @return array<int,array{id:string,number:int,type:string,status:string}> */
    public function listSequenceSteps(string $sequenceId): array
    {
        $data = $this->request('GET', "/sequences/{$sequenceId}/steps");
        return $this->unwrap($data);
    }

    /** @return array<int,array{id:string,label:string,fieldType:string,mappingDefaultField:?string}> */
    public function listFields(): array
    {
        $data = $this->request('GET', '/fields', ['systemFields' => true]);
        return $this->unwrap($data);
    }

    /** @return array<int,array{id:string,name:string}> */
    public function listTags(): array
    {
        $data = $this->request('GET', '/contacts/tags');
        return $this->unwrap($data);
    }

    /**
     * Enrolls prospects into a sequence step. Async on Saleshandy's side --
     * returns a requestId, poll checkImportStatus() to confirm completion.
     *
     * @param array<int,array<string,string>> $prospectList each entry keyed by Saleshandy field label
     * @param array<int,string> $tags applied to the whole batch (Saleshandy has no per-prospect tag on this endpoint)
     */
    public function pushProspects(string $stepId, array $prospectList, array $tags = [], string $conflictAction = 'addMissingFields'): ?string
    {
        $data = $this->request('POST', '/sequences/prospects/import-with-field-name', [
            'stepId' => $stepId,
            'prospectList' => $prospectList,
            'conflictAction' => $conflictAction,
            'tags' => $tags,
            'verifyProspects' => false,
        ]);
        $payload = $this->unwrap($data);
        // requestId's exact key isn't confirmed by the CLI source (it never
        // reads its own response) -- checked defensively, and its absence
        // isn't fatal since the import call itself already succeeded (2xx).
        return $payload['requestId'] ?? $payload['request_id'] ?? $payload['id'] ?? null;
    }

    /** @return array{isCompleted:bool,failedProspectsURL:?string} */
    public function checkImportStatus(string $requestId): array
    {
        $data = $this->request('GET', "/prospects/import-status/{$requestId}");
        $payload = $this->unwrap($data);
        return [
            'isCompleted' => (bool) ($payload['isCompleted'] ?? false),
            'failedProspectsURL' => $payload['failedProspectsURL'] ?? null,
        ];
    }

    /**
     * Per-recipient send/open/reply/bounce activity for a sequence in a
     * date window -- the source for pulling delivery statuses back in.
     *
     * @return array<int,array{email:string,sentAt:?string,replied:bool,bounced:bool,unsubscribed:bool}>
     */
    public function fetchSequenceActivity(string $sequenceId, string $startDate, string $endDate): array
    {
        $rows = [];
        $page = 1;
        do {
            $data = $this->request('POST', '/analytics/consolidated-stats', [
                'sequenceIds' => [$sequenceId],
                'startDate' => $startDate,
                'endDate' => $endDate,
                'pageNum' => $page,
                'pageLimit' => 100,
            ]);
            $payload = $this->unwrap($data);
            $pageRows = $payload['data'] ?? [];
            foreach ($pageRows as $row) {
                $rows[] = [
                    'email' => strtolower(trim((string) ($row['Recipient Email'] ?? ''))),
                    'sentAt' => $row['Email Sent At'] ?? null,
                    'replied' => ($row['Replied'] ?? 'No') === 'Yes',
                    'bounced' => ($row['Bounced'] ?? 'No') === 'Yes',
                    'unsubscribed' => ($row['Unsubscribed'] ?? 'No') === 'Yes',
                ];
            }
            $hasMore = !empty($payload['hasMore']);
            $page++;
        } while ($hasMore);

        return $rows;
    }

    /**
     * Pulls delivery/reply/bounce activity for one campaign's linked
     * sequence and applies it to lead_campaign_assignments.delivery_status,
     * reusing the same bounce-suppression path as the CSV backfill
     * importer (CampaignHistoryImporter) and the bulk delivery-status
     * action (campaign_assignment_update.php) -- one implementation of
     * "what a bounce/reply does", called from three entry points.
     *
     * @param array<string,mixed> $campaign a row from `campaigns` (must have saleshandy_sequence_id)
     * @return array{matched:int,bounced:int,replied:int}
     */
    public function syncCampaign(PDO $db, array $campaign, int $userId): array
    {
        $stats = ['matched' => 0, 'bounced' => 0, 'replied' => 0];
        $sequenceId = $campaign['saleshandy_sequence_id'];
        if (!$sequenceId) {
            return $stats;
        }

        $startDate = $campaign['saleshandy_last_synced_at'] ?? $campaign['created_at'];
        $startDate = date('Y-m-d', strtotime((string) $startDate));
        $endDate = date('Y-m-d');

        $activity = $this->fetchSequenceActivity($sequenceId, $startDate, $endDate);
        if (!$activity) {
            $db->prepare('UPDATE campaigns SET saleshandy_last_synced_at = NOW() WHERE id = ?')->execute([$campaign['id']]);
            return $stats;
        }

        $assignStmt = $db->prepare(
            "SELECT a.id FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
              WHERE a.campaign_id = ? AND l.email = ?"
        );
        $updateStmt = $db->prepare('UPDATE lead_campaign_assignments SET delivery_status = ? WHERE id = ?');

        foreach ($activity as $row) {
            if ($row['email'] === '') {
                continue;
            }
            $assignStmt->execute([$campaign['id'], $row['email']]);
            $assignmentId = $assignStmt->fetchColumn();
            if (!$assignmentId) {
                continue; // activity for an email not assigned to this campaign locally
            }

            if ($row['bounced']) {
                $status = 'Bounced';
                $stats['bounced']++;
                WaveAssigner::suppressByEmail($db, $row['email'], $userId, "Saleshandy sync: {$campaign['name']}", $status);
            } elseif ($row['replied']) {
                $status = 'Replied';
                $stats['replied']++;
            } elseif ($row['unsubscribed']) {
                $status = 'Paused'; // no dedicated "Unsubscribed" value in DELIVERY_STATUSES
            } elseif ($row['sentAt']) {
                $status = 'Active';
            } else {
                $status = 'Waiting';
            }

            $updateStmt->execute([$status, $assignmentId]);
            $stats['matched']++;
        }

        $db->prepare('UPDATE campaigns SET saleshandy_last_synced_at = NOW() WHERE id = ?')->execute([$campaign['id']]);

        return $stats;
    }

    /**
     * Saleshandy wraps every successful response as {..., "payload": ...}
     * (confirmed via the official CLI's response interceptor, which
     * unwraps this same key client-side) -- list endpoints return an
     * array directly under it, others an object.
     *
     * @return array<mixed>
     */
    private function unwrap(array $data): array
    {
        $payload = $data['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string,mixed> $params query params (GET) or JSON body (POST/PUT)
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $url = self::BASE_URL . $path;
        $ch = curl_init();

        if ($method === 'GET' && $params) {
            $url .= '?' . http_build_query($params);
        }

        // Header set matches Saleshandy's own official CLI exactly (see
        // class docblock) -- x-api-key, not Authorization: Bearer, and
        // Content-Type is sent unconditionally, including on GET.
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'sh-application: open-api',
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new SaleshandyApiException("Saleshandy API request failed: {$error}");
        }

        $decoded = json_decode((string) $body, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            // Three known error shapes seen across this API: the app-level
            // envelope {code, type, message}, a NestJS validation envelope
            // {statusCode, message|messages}, and a gateway-level envelope
            // {error, error_code, error_message} (seen on a header-validation
            // rejection, likely from a layer in front of the app itself).
            if (is_array($decoded)) {
                $message = $decoded['error_message'] ?? $decoded['message'] ?? $decoded['messages'] ?? $body;
                if (is_array($message)) {
                    $message = implode(', ', $message);
                }
                $code = $decoded['error_code'] ?? $decoded['code'] ?? $decoded['statusCode'] ?? null;
            } else {
                $message = $body;
                $code = null;
            }
            $suffix = $code !== null ? " (code {$code})" : '';
            throw new SaleshandyApiException("Saleshandy API error ({$httpCode}){$suffix}: {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}

class SaleshandyApiException extends RuntimeException
{
}
