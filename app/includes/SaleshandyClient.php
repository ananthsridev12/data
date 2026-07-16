<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/WaveAssigner.php';

/**
 * Thin cURL wrapper around Saleshandy's REST API. No SDK/Composer, matching
 * the rest of this app.
 *
 * IMPORTANT -- endpoint paths below are best-effort, based on (a) the one
 * path Saleshandy documents explicitly ("POST /v1/leads/bulk-actions/add-to-sequence")
 * and (b) the request/response shapes of the equivalent tools on the
 * Saleshandy MCP connection used to design this integration, which wraps
 * this same API but doesn't expose literal endpoint URLs. Every method
 * below is a single, clearly-labeled place to correct once real API docs
 * or a support-provided spec are in hand -- do a cheap smoke test
 * (listSequences() is the safest one, read-only) before relying on push/pull.
 */
class SaleshandyClient
{
    private const BASE_URL = 'https://api.saleshandy.com/v1';

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

    /** @return array<int,array{id:string,title:string,active:bool}> */
    public function listSequences(): array
    {
        $data = $this->request('GET', '/sequences', ['pageSize' => 100]);
        return $data['payload'] ?? [];
    }

    /** @return array<int,array{id:string,name:string}> */
    public function listSequenceSteps(string $sequenceId): array
    {
        $data = $this->request('GET', "/sequences/{$sequenceId}/steps");
        return $data['payload'] ?? [];
    }

    /** @return array<int,array{id:string,label:string,fieldType:string}> */
    public function listFields(): array
    {
        $data = $this->request('GET', '/fields', ['systemFields' => 'true']);
        return $data['payload'] ?? [];
    }

    /** @return array<int,array{id:string,name:string}> */
    public function listTags(): array
    {
        $data = $this->request('GET', '/tags', ['pageSize' => 100]);
        return $data['payload'] ?? [];
    }

    /**
     * Enrolls prospects into a sequence step. Async on Saleshandy's side --
     * returns a requestId, poll checkImportStatus() to confirm completion.
     *
     * @param array<int,array<string,string>> $prospectList each entry keyed by Saleshandy field label
     * @param array<int,string> $tags applied to the whole batch (Saleshandy has no per-prospect tag on this endpoint)
     */
    public function pushProspects(string $stepId, array $prospectList, array $tags = [], string $conflictAction = 'addMissingFields'): string
    {
        $data = $this->request('POST', '/prospects/import-to-step', [
            'stepId' => $stepId,
            'prospectList' => $prospectList,
            'conflictAction' => $conflictAction,
            'tags' => $tags,
            'verifyProspects' => false,
        ]);
        $requestId = $data['payload']['requestId'] ?? null;
        if (!$requestId) {
            throw new SaleshandyApiException('Push accepted but no requestId was returned.');
        }
        return $requestId;
    }

    /** @return array{isCompleted:bool,failedProspectsURL:?string} */
    public function checkImportStatus(string $requestId): array
    {
        $data = $this->request('GET', "/prospects/import/{$requestId}/status");
        return $data['payload'] ?? ['isCompleted' => false, 'failedProspectsURL' => null];
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
            $data = $this->request('GET', '/analytics/consolidated-stats', [
                'sequenceIds' => [$sequenceId],
                'startDate' => $startDate,
                'endDate' => $endDate,
                'pageNum' => $page,
                'pageLimit' => 100,
            ]);
            $pageRows = $data['payload']['data'] ?? [];
            foreach ($pageRows as $row) {
                $rows[] = [
                    'email' => strtolower(trim((string) ($row['Recipient Email'] ?? ''))),
                    'sentAt' => $row['Email Sent At'] ?? null,
                    'replied' => ($row['Replied'] ?? 'No') === 'Yes',
                    'bounced' => ($row['Bounced'] ?? 'No') === 'Yes',
                    'unsubscribed' => ($row['Unsubscribed'] ?? 'No') === 'Yes',
                ];
            }
            $hasMore = !empty($data['payload']['hasMore']);
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

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
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
            $message = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;
            throw new SaleshandyApiException("Saleshandy API error ({$httpCode}): {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}

class SaleshandyApiException extends RuntimeException
{
}
