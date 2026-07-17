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
     * Checks Saleshandy's email verification status for a batch of
     * addresses. The exact set of status strings Saleshandy returns isn't
     * documented anywhere accessible while building this (not in their
     * own CLI's source, which just passes the value through) -- callers
     * should classify the raw string via classifyVerificationTier() below
     * rather than comparing it directly, since that method matches
     * keywords case-insensitively instead of assuming one exact spelling.
     *
     * @param array<int,string> $emails
     * @return array<string,string> email => raw Saleshandy status string
     */
    public function checkVerificationStatus(array $emails): array
    {
        if (!$emails) {
            return [];
        }
        $data = $this->request('POST', '/prospects/verification-status', ['emails' => array_values($emails)]);
        $payload = $this->unwrap($data);
        $results = [];
        foreach ($payload as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                $results[$email] = (string) ($row['status'] ?? '');
            }
        }
        return $results;
    }

    /**
     * Buckets a raw Saleshandy verification status into one of three
     * tiers by keyword, since the exact strings Saleshandy uses aren't
     * confirmed (see checkVerificationStatus()): 'bad' (never send --
     * invalid/undeliverable/rejected), 'risky' (deliverability uncertain
     * -- catch-all/accept-all/unknown addresses, admin's call whether to
     * send), or 'good' (verified deliverable). An unrecognized string is
     * treated as 'risky' rather than 'good', so an unexpected status
     * value fails toward caution instead of silently sending to it.
     */
    public static function classifyVerificationTier(string $rawStatus): string
    {
        $s = mb_strtolower(trim($rawStatus));
        if ($s === '') {
            return 'risky';
        }
        if (preg_match('/\b(bad|invalid|undeliverable|reject\w*|bounc\w*|block\w*|disposable)\b/', $s)) {
            return 'bad';
        }
        if (preg_match('/\b(good|valid|deliverable|safe|verifi\w*)\b/', $s)) {
            return 'good';
        }
        return 'risky'; // covers risky/catch-all/accept-all/unknown/unverified/anything else
    }

    /**
     * Per-recipient send/open/reply/bounce activity for a sequence in a
     * date window -- the source for pulling delivery statuses back in,
     * and (via pullNewProspects()) for discovering prospects that were
     * added to the sequence directly in Saleshandy rather than pushed
     * from here.
     *
     * IMPORTANT LIMITATION: this only returns prospects who've had at
     * least one email send/activity event in the window -- a prospect
     * enrolled in the sequence but not yet reached (still queued behind
     * an earlier step, paused, etc.) won't appear here at all. There is
     * no endpoint in Saleshandy's API (confirmed against their own
     * official CLI's source) that lists every prospect enrolled in a
     * sequence regardless of activity, so the count returned here can be
     * legitimately lower than Saleshandy's own "N prospects" figure for
     * the sequence.
     *
     * @return array<int,array{email:string,name:string,sentAt:?string,replied:bool,bounced:bool,unsubscribed:bool,stepNumber:?int}>
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
                    'name' => trim((string) ($row['Recipient name'] ?? $row['Recipient Name'] ?? '')),
                    'sentAt' => $row['Email Sent At'] ?? null,
                    'replied' => ($row['Replied'] ?? 'No') === 'Yes',
                    'bounced' => ($row['Bounced'] ?? 'No') === 'Yes',
                    'unsubscribed' => ($row['Unsubscribed'] ?? 'No') === 'Yes',
                    'stepNumber' => isset($row['Step Number']) ? (int) $row['Step Number'] : null,
                ];
            }
            $hasMore = !empty($payload['hasMore']);
            $page++;
        } while ($hasMore);

        return $rows;
    }

    /**
     * A prospect can have one activity row per sequence step -- this
     * collapses all of an email's rows into one aggregated record so a
     * signal on any single row (a reply, a bounce, a later step reached)
     * isn't lost by only looking at that email's first or last row.
     *
     * @param array<int,array{email:string,name:string,sentAt:?string,replied:bool,bounced:bool,unsubscribed:bool,stepNumber:?int}> $activity
     * @return array<string,array{name:string,sentAt:?string,replied:bool,bounced:bool,unsubscribed:bool,currentStep:?int}>
     */
    private function aggregateActivityByEmail(array $activity): array
    {
        $byEmail = [];
        foreach ($activity as $row) {
            if ($row['email'] === '') {
                continue;
            }
            if (!isset($byEmail[$row['email']])) {
                $byEmail[$row['email']] = ['name' => $row['name'], 'sentAt' => $row['sentAt'], 'replied' => false, 'bounced' => false, 'unsubscribed' => false, 'currentStep' => null];
            }
            $agg = &$byEmail[$row['email']];
            $agg['replied'] = $agg['replied'] || $row['replied'];
            $agg['bounced'] = $agg['bounced'] || $row['bounced'];
            $agg['unsubscribed'] = $agg['unsubscribed'] || $row['unsubscribed'];
            if ($agg['name'] === '' && $row['name'] !== '') {
                $agg['name'] = $row['name'];
            }
            if ($row['sentAt'] && (!$agg['sentAt'] || $row['sentAt'] < $agg['sentAt'])) {
                $agg['sentAt'] = $row['sentAt']; // earliest send time
            }
            if ($row['stepNumber'] !== null && $row['stepNumber'] > ($agg['currentStep'] ?? 0)) {
                $agg['currentStep'] = $row['stepNumber']; // furthest step reached
            }
            unset($agg);
        }
        return $byEmail;
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
        $byEmail = $this->aggregateActivityByEmail($activity);
        if (!$byEmail) {
            $db->prepare('UPDATE campaigns SET saleshandy_last_synced_at = NOW() WHERE id = ?')->execute([$campaign['id']]);
            return $stats;
        }

        $assignStmt = $db->prepare(
            "SELECT a.id FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
              WHERE a.campaign_id = ? AND l.email = ?"
        );
        $updateStmt = $db->prepare(
            'UPDATE lead_campaign_assignments
                SET delivery_status = ?, saleshandy_current_step = ?, saleshandy_synced_at = NOW(),
                    email_sent = email_sent OR ?, email_sent_at = COALESCE(email_sent_at, ?)
              WHERE id = ?'
        );

        foreach ($byEmail as $email => $row) {
            $assignStmt->execute([$campaign['id'], $email]);
            $assignmentId = $assignStmt->fetchColumn();
            if (!$assignmentId) {
                continue; // activity for an email not assigned to this campaign locally
            }

            if ($row['bounced']) {
                $status = 'Bounced';
                $stats['bounced']++;
                WaveAssigner::suppressByEmail($db, $email, $userId, "Saleshandy sync: {$campaign['name']}", $status);
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

            $emailSent = $row['sentAt'] ? 1 : 0;
            $emailSentAt = $row['sentAt'] ? date('Y-m-d', strtotime((string) $row['sentAt'])) : null;
            $updateStmt->execute([$status, $row['currentStep'], $emailSent, $emailSentAt, $assignmentId]);
            $stats['matched']++;
        }

        $db->prepare('UPDATE campaigns SET saleshandy_last_synced_at = NOW() WHERE id = ?')->execute([$campaign['id']]);

        return $stats;
    }

    /**
     * Pulls in prospects that are already enrolled in this campaign's
     * linked Saleshandy sequence but were never pushed from here (added
     * directly in Saleshandy, or before this integration existed). Unlike
     * syncCampaign(), which only updates assignments that already exist
     * locally, this creates the lead (if missing) and the assignment (if
     * missing) instead of skipping. A lead created this way only has an
     * email and a name split from Saleshandy's "Recipient name" -- no
     * company, title, etc. are available from this endpoint, hence
     * Company Name being optional (see sql/013_pull_from_saleshandy.sql).
     *
     * The assignment is marked status='pushed' (not 'exported') so our
     * own "Push to Saleshandy" never re-sends it -- it's already there.
     *
     * Always looks back the full 2 years Saleshandy's analytics endpoint
     * allows, not bounded by this campaign's local creation date -- the
     * whole point of this method is backfilling activity that predates
     * our local record of the campaign, so bounding by that date would
     * silently cut off exactly the history it's meant to find.
     *
     * @param array<string,mixed> $campaign a row from `campaigns` (must have saleshandy_sequence_id)
     * @return array{leads_created:int,assignments_created:int,already_present:int,distinct_prospects_found:int}
     */
    public function pullNewProspects(PDO $db, array $campaign, int $userId): array
    {
        $stats = ['leads_created' => 0, 'assignments_created' => 0, 'already_present' => 0, 'distinct_prospects_found' => 0];
        $sequenceId = $campaign['saleshandy_sequence_id'];
        if (!$sequenceId) {
            return $stats;
        }

        $startDate = date('Y-m-d', strtotime('-2 years'));
        $endDate = date('Y-m-d');

        $activity = $this->fetchSequenceActivity($sequenceId, $startDate, $endDate);
        $byEmail = $this->aggregateActivityByEmail($activity);
        $stats['distinct_prospects_found'] = count($byEmail);

        $findLead = $db->prepare('SELECT id FROM leads WHERE email = ?');
        $insertLead = $db->prepare('INSERT INTO leads (email, first_name, last_name) VALUES (?, ?, ?)');
        $findAssignment = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
        $insertAssignment = $db->prepare(
            "INSERT INTO lead_campaign_assignments
                (lead_id, campaign_id, assigned_by, status, exported_at, delivery_status,
                 saleshandy_current_step, saleshandy_synced_at, email_sent, email_sent_at)
             VALUES (?, ?, ?, 'pushed', ?, ?, ?, NOW(), ?, ?)"
        );

        foreach ($byEmail as $email => $row) {
            $findLead->execute([$email]);
            $leadId = $findLead->fetchColumn();

            if (!$leadId) {
                $nameParts = preg_split('/\s+/', $row['name'], 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';
                $insertLead->execute([$email, $firstName, $lastName]);
                $leadId = (int) $db->lastInsertId();
                $stats['leads_created']++;
            }

            $findAssignment->execute([$leadId, $campaign['id']]);
            if ($findAssignment->fetchColumn()) {
                $stats['already_present']++;
                continue;
            }

            $deliveryStatus = $row['bounced'] ? 'Bounced'
                : ($row['replied'] ? 'Replied'
                : ($row['unsubscribed'] ? 'Paused'
                : ($row['sentAt'] ? 'Active' : 'Waiting')));
            $exportedAt = $row['sentAt'] ? date('Y-m-d H:i:s', strtotime((string) $row['sentAt'])) : date('Y-m-d H:i:s');
            $emailSent = $row['sentAt'] ? 1 : 0;
            $emailSentAt = $row['sentAt'] ? date('Y-m-d', strtotime((string) $row['sentAt'])) : null;

            $insertAssignment->execute([
                $leadId, $campaign['id'], $userId, $exportedAt, $deliveryStatus,
                $row['currentStep'], $emailSent, $emailSentAt,
            ]);
            $stats['assignments_created']++;

            if ($row['bounced']) {
                WaveAssigner::suppressByEmail($db, $email, $userId, "Saleshandy pull-in: {$campaign['name']}", 'Bounced');
            }
        }

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
