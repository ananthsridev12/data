<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/WaveAssigner.php';
require_once __DIR__ . '/TagRepository.php';

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
        $payload = $this->unwrap($data);
        // Defensive, same as findProspectId()'s /contacts handling: if this
        // list endpoint turns out to wrap its array under a nested "data"
        // key rather than returning it directly under payload (confirmed
        // for /contacts, not independently confirmed for /fields), fall
        // back to that instead of returning a single malformed row that
        // silently makes every field lookup fail.
        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
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
     * Looks up a prospect's Saleshandy-internal id by email (GET
     * /contacts?search=..., a contact-level listing endpoint independent
     * of any sequence) -- needed for updateProspectAttributes() below,
     * which addresses a prospect by id rather than email.
     */
    public function findProspectId(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $data = $this->request('GET', '/contacts', ['search' => $email, 'pageSize' => 10, 'page' => 1]);
        $payload = $this->unwrap($data);
        $rows = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        foreach ($rows as $row) {
            if (is_array($row) && strtolower(trim((string) ($row['email'] ?? ''))) === $email) {
                return isset($row['id']) ? (string) $row['id'] : null;
            }
        }
        return null;
    }

    /**
     * Updates a prospect's field values directly on their Saleshandy
     * contact record (POST /prospects/{id}/attribute) -- contact level,
     * not tied to any sequence/campaign. This is the right tool for
     * refreshing an already-pushed lead's data after it's been edited
     * here (e.g. via re-import): unlike re-running pushProspects()
     * against the sequence-import endpoint, it never re-enrolls the
     * prospect or touches their current step.
     *
     * IMPORTANT: a 2xx response here does NOT mean every field was
     * actually saved -- confirmed via Saleshandy's own CLI
     * (attribute-set.js), the response is {results: [{fieldId, success},
     * ...]}, so each field can individually succeed or fail (wrong field
     * type, a read-only/system field, etc.) inside an overall-successful
     * call. Callers must check the returned failed-field-id list rather
     * than assuming success from the absence of a thrown exception.
     *
     * Observed in practice: a full batch can also come back with EVERY
     * field failed at once, including fields (First Name, Last Name) that
     * saved fine on their own moments earlier -- one poisoned value (e.g. a
     * dropdown/select field rejecting a value outside its predefined
     * options) appears to take the whole batch down rather than failing
     * independently. When that all-or-nothing signature shows up, retry
     * one field at a time so the genuinely valid fields still get saved
     * and the actual culprit is pinned down precisely instead of reporting
     * an opaque "N of N failed" for the whole batch.
     *
     * @param array<string,string> $fieldIdToValue Saleshandy field id => new value
     * @return array<int,string> field ids Saleshandy reported as failed (empty = every field saved)
     */
    public function updateProspectAttributes(string $prospectId, array $fieldIdToValue): array
    {
        if (!$fieldIdToValue) {
            return [];
        }

        $failed = $this->sendAttributeBatch($prospectId, $fieldIdToValue);

        if (count($fieldIdToValue) > 1 && count($failed) === count($fieldIdToValue)) {
            $failed = [];
            foreach ($fieldIdToValue as $fieldId => $value) {
                if ($this->sendAttributeBatch($prospectId, [$fieldId => $value])) {
                    $failed[] = (string) $fieldId;
                }
                usleep(150_000);
            }
        }

        return $failed;
    }

    /**
     * Raw single-call implementation of the attribute update -- issues one
     * POST /prospects/{id}/attribute request for the given fields and
     * returns the field ids Saleshandy reported as failed. Split out from
     * updateProspectAttributes() so that method can retry field-by-field
     * without duplicating the request/response handling.
     *
     * @param array<string,string> $fieldIdToValue Saleshandy field id => new value
     * @return array<int,string> field ids Saleshandy reported as failed
     */
    private function sendAttributeBatch(string $prospectId, array $fieldIdToValue): array
    {
        $attributes = [];
        foreach ($fieldIdToValue as $fieldId => $value) {
            $attributes[] = ['fieldId' => (string) $fieldId, 'attributeValue' => (string) $value];
        }
        $data = $this->request('POST', "/prospects/{$prospectId}/attribute", ['attributes' => $attributes]);
        $payload = $this->unwrap($data);
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        $failed = [];
        foreach ($results as $r) {
            if (is_array($r) && isset($r['fieldId']) && empty($r['success'])) {
                $failed[] = (string) $r['fieldId'];
            }
        }
        return $failed;
    }

    /**
     * Pushes each checked, already-pushed lead's current field values
     * (per the enabled Saleshandy Field Mapping) to its existing
     * Saleshandy contact via updateProspectAttributes() -- the answer to
     * "I updated leads via re-import, how do I get that into Saleshandy":
     * contact level, not campaign/sequence level, so it can't disturb
     * anyone's sequence position. Saleshandy's prospect id is resolved by
     * email once and cached on leads.saleshandy_prospect_id.
     *
     * @param int[] $assignmentIds
     * @return array{updated:int, partial:int, not_found:int, skipped_not_pushed:int, no_fields:int, errors:array<int,string>, details:array<int,string>, stale_mapping_labels:array<int,string>}
     */
    public function syncFieldsToSaleshandy(PDO $db, array $assignmentIds, int $campaignId): array
    {
        $stats = [
            'updated' => 0, 'partial' => 0, 'not_found' => 0, 'skipped_not_pushed' => 0, 'no_fields' => 0,
            'errors' => [], 'details' => [], 'stale_mapping_labels' => [],
        ];
        if (!$assignmentIds) {
            return $stats;
        }

        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $stmt = $db->prepare(
            "SELECT a.status, l.*, v.label AS vertical_label, s.label AS service_label
               FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
               LEFT JOIN verticals v ON v.id = l.vertical_id
               LEFT JOIN services s ON s.id = l.service_id
              WHERE a.campaign_id = ? AND a.id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$campaignId], $assignmentIds));
        $rows = $stmt->fetchAll();

        $eligible = array_values(array_filter($rows, static fn(array $r) => $r['status'] === 'pushed'));
        $stats['skipped_not_pushed'] = count($rows) - count($eligible);
        if (!$eligible) {
            return $stats;
        }

        $enabledMappings = $db->query(
            'SELECT lead_field_key, saleshandy_label FROM saleshandy_field_mappings WHERE enabled = 1'
        )->fetchAll();

        $fieldsByLabel = [];
        foreach ($this->listFields() as $f) {
            if (isset($f['label'], $f['id'])) {
                $fieldsByLabel[$f['label']] = (string) $f['id'];
            }
        }
        $labelByFieldId = array_flip($fieldsByLabel);
        if (!$fieldsByLabel) {
            // Every lead below will otherwise fail silently (no matching
            // label for anything, not even First/Last Name) -- surface
            // this loudly instead, since it means Saleshandy's field list
            // came back empty or in an unexpected shape.
            $stats['errors'][] = 'Could not retrieve any fields from Saleshandy (listFields() returned none) -- nothing was sent. Check the API key and try again.';
            $stats['no_fields'] = count($eligible);
            return $stats;
        }

        // Which enabled mappings don't currently match a real Saleshandy
        // field -- reported once here (mapping-level, not per-lead) so
        // "only First/Last Name went out, none of my other mapped fields"
        // has a specific, visible reason instead of just quietly sending
        // a smaller payload than configured.
        foreach ($enabledMappings as $m) {
            if (!isset($fieldsByLabel[$m['saleshandy_label']])) {
                $stats['stale_mapping_labels'][] = $m['saleshandy_label'];
            }
        }

        $resolveValue = static function (array $lead, string $key): string {
            if ($key === 'vertical') {
                return (string) ($lead['vertical_label'] ?? '');
            }
            if ($key === 'service') {
                return (string) ($lead['service_label'] ?? '');
            }
            return (string) ($lead[$key] ?? '');
        };

        $updateProspectId = $db->prepare('UPDATE leads SET saleshandy_prospect_id = ? WHERE id = ?');

        foreach ($eligible as $i => $lead) {
            if ($i > 0) {
                // A light gap between leads (on top of request()'s
                // retry-with-backoff) so a big batch doesn't front-load a
                // burst of calls and trip Saleshandy's rate limit in the
                // first place -- request() still recovers if it does.
                usleep(150_000);
            }

            $prospectId = $lead['saleshandy_prospect_id'];
            if (!$prospectId) {
                try {
                    $prospectId = $this->findProspectId($lead['email']);
                } catch (SaleshandyApiException $ex) {
                    $stats['errors'][] = "{$lead['email']}: {$ex->getMessage()}";
                    continue;
                }
                if ($prospectId) {
                    $updateProspectId->execute([$prospectId, $lead['id']]);
                }
            }
            if (!$prospectId) {
                $stats['not_found']++;
                continue;
            }

            $fieldIdToValue = [];
            foreach ([['First Name', 'first_name'], ['Last Name', 'last_name']] as [$label, $key]) {
                if (!isset($fieldsByLabel[$label])) {
                    continue;
                }
                $value = $resolveValue($lead, $key);
                if ($value !== '') {
                    $fieldIdToValue[$fieldsByLabel[$label]] = $value;
                }
            }
            foreach ($enabledMappings as $m) {
                if (!isset($fieldsByLabel[$m['saleshandy_label']])) {
                    continue;
                }
                $value = $resolveValue($lead, $m['lead_field_key']);
                if ($value !== '') {
                    $fieldIdToValue[$fieldsByLabel[$m['saleshandy_label']]] = $value;
                }
            }

            if (!$fieldIdToValue) {
                // Every enabled mapping's label matched a real Saleshandy
                // field (or $fieldsByLabel would already have been empty
                // and returned above), but this lead had no non-empty
                // value for any of them -- previously fell through
                // silently with nothing to show for it.
                $stats['no_fields']++;
                continue;
            }

            // Which labels/values were actually attempted for this lead --
            // surfaced back to the admin (prospect id + field list) so a
            // "Saleshandy confirmed success but I don't see the change"
            // report can be checked against the *exact* record and fields
            // touched, rather than however Saleshandy's UI search happens
            // to resolve that email (which may not be the same record if
            // there's more than one contact with it).
            $sentLabels = array_map(
                static fn(string $fieldId) => $labelByFieldId[$fieldId] ?? $fieldId,
                array_keys($fieldIdToValue)
            );

            try {
                $failedFieldIds = $this->updateProspectAttributes($prospectId, $fieldIdToValue);
                if (!$failedFieldIds) {
                    $stats['updated']++;
                    $stats['details'][] = "{$lead['email']} -> Saleshandy prospect {$prospectId}: sent " . implode(', ', $sentLabels);
                } else {
                    $stats['partial']++;
                    $failedLabels = array_map(
                        static fn(string $fieldId) => $labelByFieldId[$fieldId] ?? $fieldId,
                        $failedFieldIds
                    );
                    $succeededCount = count($fieldIdToValue) - count($failedFieldIds);
                    $stats['errors'][] = "{$lead['email']}: Saleshandy rejected " . count($failedFieldIds)
                        . ' of ' . count($fieldIdToValue) . " field(s) ({$succeededCount} saved) -- failed: "
                        . implode(', ', $failedLabels);
                    $stats['details'][] = "{$lead['email']} -> Saleshandy prospect {$prospectId}: sent " . implode(', ', $sentLabels);
                }
            } catch (SaleshandyApiException $ex) {
                $stats['errors'][] = "{$lead['email']}: {$ex->getMessage()}";
            }
        }

        return $stats;
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
     * "Open Count"/"Last Opened At" are Saleshandy's own cumulative
     * snapshot as of this fetch (confirmed via the documented shape of
     * this same endpoint) -- not a per-day open log, so they answer "has
     * this recipient opened, and when most recently" rather than "which
     * day did each open happen on".
     *
     * "Current Sentiment" (Positive/Negative/Neutral/Uncategorized) is
     * Saleshandy's own live categorization of a prospect's reply outcome --
     * only meaningful once Replied is "Yes", but returned on every row
     * regardless, so it's parsed unconditionally like everything else here.
     *
     * @return array<int,array{email:string,name:string,sentAt:?int,replied:bool,bounced:bool,unsubscribed:bool,stepNumber:?int,openCount:int,lastOpenedAt:?int,sentiment:?string}>
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
                $sentiment = trim((string) ($row['Current Sentiment'] ?? ''));
                $rows[] = [
                    'email' => strtolower(trim((string) ($row['Recipient Email'] ?? ''))),
                    'name' => trim((string) ($row['Recipient name'] ?? $row['Recipient Name'] ?? '')),
                    'sentAt' => self::parseSaleshandyDate($row['Email Sent At'] ?? null),
                    'replied' => ($row['Replied'] ?? 'No') === 'Yes',
                    'bounced' => ($row['Bounced'] ?? 'No') === 'Yes',
                    'unsubscribed' => ($row['Unsubscribed'] ?? 'No') === 'Yes',
                    'stepNumber' => isset($row['Step Number']) ? (int) $row['Step Number'] : null,
                    'openCount' => isset($row['Open Count']) ? (int) $row['Open Count'] : 0,
                    'lastOpenedAt' => self::parseSaleshandyDate($row['Last Opened At'] ?? null),
                    'sentiment' => $sentiment !== '' ? $sentiment : null,
                ];
            }
            $hasMore = !empty($payload['hasMore']);
            $page++;
        } while ($hasMore);

        return $rows;
    }

    /**
     * Parses Saleshandy's "Email Sent At" (and similar) date strings into a
     * Unix timestamp, or null if unparseable -- callers must never fall
     * back to date()-on-false, which silently produces 1970-01-01.
     *
     * Confirmed against a real API response (not the API docs' simplified
     * example): Saleshandy returns JavaScript's Date.toString() format,
     * e.g. "Mon Jul 20 2026 20:20:43 GMT+5:30 (Asia/Kolkata)" -- the
     * trailing parenthetical IANA zone name (which the docs example
     * omits) makes PHP's strtotime() return false on the string as-is,
     * even though it parses the same string fine with that suffix
     * stripped. This is what was producing "1970-01-01" everywhere an
     * Email Sent At date was displayed.
     */
    private static function parseSaleshandyDate(?string $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $raw = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $raw));
        $timestamp = strtotime($raw);
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * A prospect can have one activity row per sequence step -- this
     * collapses all of an email's rows into one aggregated record so a
     * signal on any single row (a reply, a bounce, a later step reached)
     * isn't lost by only looking at that email's first or last row.
     *
     * @param array<int,array{email:string,name:string,sentAt:?int,replied:bool,bounced:bool,unsubscribed:bool,stepNumber:?int,openCount:int,lastOpenedAt:?int,sentiment:?string}> $activity
     * @return array<string,array{name:string,sentAt:?int,replied:bool,bounced:bool,unsubscribed:bool,currentStep:?int,openCount:int,lastOpenedAt:?int,sentiment:?string}>
     */
    private function aggregateActivityByEmail(array $activity): array
    {
        $byEmail = [];
        foreach ($activity as $row) {
            if ($row['email'] === '') {
                continue;
            }
            if (!isset($byEmail[$row['email']])) {
                $byEmail[$row['email']] = ['name' => $row['name'], 'sentAt' => $row['sentAt'], 'replied' => false, 'bounced' => false, 'unsubscribed' => false, 'currentStep' => null, 'openCount' => 0, 'lastOpenedAt' => null, 'sentiment' => null];
            }
            $agg = &$byEmail[$row['email']];
            $agg['replied'] = $agg['replied'] || $row['replied'];
            $agg['bounced'] = $agg['bounced'] || $row['bounced'];
            $agg['unsubscribed'] = $agg['unsubscribed'] || $row['unsubscribed'];
            if ($agg['name'] === '' && $row['name'] !== '') {
                $agg['name'] = $row['name'];
            }
            // Numeric timestamp comparison -- previously compared the raw
            // date *strings* lexicographically, which doesn't reliably
            // track real chronological order (e.g. "Fri Jan 02 2026" sorts
            // before "Mon Dec 29 2025" alphabetically despite being later).
            if ($row['sentAt'] !== null && ($agg['sentAt'] === null || $row['sentAt'] < $agg['sentAt'])) {
                $agg['sentAt'] = $row['sentAt']; // earliest send time
            }
            if ($row['stepNumber'] !== null && $row['stepNumber'] > ($agg['currentStep'] ?? 0)) {
                $agg['currentStep'] = $row['stepNumber']; // furthest step reached
            }
            // A lead can have one activity row per step, each with its own
            // Open Count -- take the highest across all of a lead's steps
            // as this lead's total opens, and the most recent open time
            // across steps as their last-opened timestamp.
            if ($row['openCount'] > $agg['openCount']) {
                $agg['openCount'] = $row['openCount'];
            }
            if ($row['lastOpenedAt'] !== null && ($agg['lastOpenedAt'] === null || $row['lastOpenedAt'] > $agg['lastOpenedAt'])) {
                $agg['lastOpenedAt'] = $row['lastOpenedAt'];
            }
            // Saleshandy reports this as the prospect's current (live)
            // sentiment, not a per-event value -- take whichever row's
            // reading comes last, same "most recent wins" spirit as
            // lastOpenedAt above.
            if ($row['sentiment'] !== null) {
                $agg['sentiment'] = $row['sentiment'];
            }
            unset($agg);
        }
        return $byEmail;
    }

    /**
     * Upserts the raw (pre-aggregation) activity rows fetchSequenceActivity()
     * returned into saleshandy_send_events, one row per real send event
     * (email actually sent, i.e. sentAt !== null). Called from both
     * syncCampaign() (narrow incremental window) and
     * backfillHistoricalDates() (full 2-year window) on the exact
     * $activity array each already fetched -- no additional Saleshandy API
     * call. Idempotent: the table's unique key on (campaign_id,
     * recipient_email, step_number, sent_date) means re-running this over
     * an overlapping/wider date range only ever updates existing rows,
     * never duplicates them -- same guarantee backfillHistoricalDates()
     * already relies on for lead_campaign_assignments.
     *
     * @param array<int,array{email:string,sentAt:?int,replied:bool,bounced:bool,unsubscribed:bool,stepNumber:?int,openCount:int,lastOpenedAt:?int,sentiment:?string}> $activity
     * @return int rows written (inserted or updated)
     */
    private function persistSendEvents(PDO $db, int $campaignId, array $activity): int
    {
        if (!$activity) {
            return 0;
        }
        $findLead = $db->prepare('SELECT id FROM leads WHERE email = ?');
        $upsert = $db->prepare(
            'INSERT INTO saleshandy_send_events
                (campaign_id, lead_id, recipient_email, step_number, sent_date, sent_at,
                 open_count, last_opened_at, replied, bounced, unsubscribed, sentiment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                lead_id = VALUES(lead_id), sent_at = VALUES(sent_at),
                open_count = VALUES(open_count), last_opened_at = VALUES(last_opened_at),
                replied = VALUES(replied), bounced = VALUES(bounced), unsubscribed = VALUES(unsubscribed),
                sentiment = VALUES(sentiment), synced_at = NOW()'
        );

        $leadIdCache = [];
        $count = 0;
        foreach ($activity as $row) {
            if ($row['email'] === '' || $row['sentAt'] === null || $row['stepNumber'] === null) {
                continue; // not yet actually sent, or missing part of the natural key
            }
            $email = $row['email'];
            if (!array_key_exists($email, $leadIdCache)) {
                $findLead->execute([$email]);
                $leadIdCache[$email] = $findLead->fetchColumn() ?: null;
            }
            $upsert->execute([
                $campaignId, $leadIdCache[$email], $email, $row['stepNumber'],
                date('Y-m-d', $row['sentAt']), date('Y-m-d H:i:s', $row['sentAt']),
                $row['openCount'], $row['lastOpenedAt'] !== null ? date('Y-m-d H:i:s', $row['lastOpenedAt']) : null,
                $row['replied'] ? 1 : 0, $row['bounced'] ? 1 : 0, $row['unsubscribed'] ? 1 : 0,
                $row['replied'] ? $row['sentiment'] : null,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Pulls delivery/reply/bounce activity for one campaign's linked
     * sequence and applies it to lead_campaign_assignments.delivery_status,
     * reusing the same bounce-suppression path as the CSV backfill
     * importer (CampaignHistoryImporter) and the bulk delivery-status
     * action (campaign_assignment_update.php) -- one implementation of
     * "what a bounce/reply does", called from three entry points.
     *
     * Also auto-releases a wave-1 leader's held group the moment sync
     * confirms the leader went out without bouncing (Active/Replied/
     * Paused) -- previously this only happened for the bounce outcome
     * (via suppressByEmail's cascade below); a leader confirmed sent had
     * to wait for someone to manually click "Release" on Campaign Leads
     * (campaign_wave_update.php), so a held group could sit stuck for
     * days after its leader's delivery was already confirmed, even
     * though delivery_status alone already looked resolved.
     *
     * IMPORTANT: the release check below cannot live *only* inside the
     * per-activity-row loop. Saleshandy's consolidated-stats endpoint is
     * filtered by the event's own date, scoped to
     * [saleshandy_last_synced_at, now] -- once a leader's send event has
     * been consumed by one sync, `saleshandy_last_synced_at` moves past
     * it and that same event will *never appear again* in a later,
     * narrower-dated sync. A leader whose delivery_status was already set
     * to Active/Replied/Paused by a sync that ran before this
     * auto-release feature existed would otherwise stay stuck forever --
     * there would be no future activity row left to re-trigger it from.
     * So after processing whatever fresh activity this run found (if
     * any), a second, unconditional pass re-checks every still-pending
     * wave-1 leader in this campaign against *already-stored*
     * delivery_status directly -- no API call needed for that part, and
     * it runs even when Saleshandy reports zero new activity.
     *
     * @param array<string,mixed> $campaign a row from `campaigns` (must have saleshandy_sequence_id)
     * @return array{matched:int,bounced:int,replied:int,released:int,verified:int,verification_error:?string,events_persisted:int}
     */
    public function syncCampaign(PDO $db, array $campaign, int $userId): array
    {
        $stats = ['matched' => 0, 'bounced' => 0, 'replied' => 0, 'released' => 0, 'verified' => 0, 'verification_error' => null, 'events_persisted' => 0];
        $sequenceId = $campaign['saleshandy_sequence_id'];
        if (!$sequenceId) {
            return $stats;
        }

        $startDate = $campaign['saleshandy_last_synced_at'] ?? $campaign['created_at'];
        $startDate = date('Y-m-d', strtotime((string) $startDate));
        $endDate = date('Y-m-d');

        $activity = $this->fetchSequenceActivity($sequenceId, $startDate, $endDate);
        $stats['events_persisted'] = $this->persistSendEvents($db, (int) $campaign['id'], $activity);
        $byEmail = $this->aggregateActivityByEmail($activity);

        if ($byEmail) {
            $assignStmt = $db->prepare(
                "SELECT a.id FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
                  WHERE a.campaign_id = ? AND l.email = ?"
            );
            $updateStmt = $db->prepare(
                'UPDATE lead_campaign_assignments
                    SET delivery_status = ?, saleshandy_current_step = ?, saleshandy_synced_at = NOW(),
                        email_sent = email_sent OR ?, email_sent_at = COALESCE(email_sent_at, ?),
                        open_count = ?, last_opened_at = ?, reply_sentiment = ?
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

                $emailSent = $row['sentAt'] !== null ? 1 : 0;
                $emailSentAt = $row['sentAt'] !== null ? date('Y-m-d', $row['sentAt']) : null;
                $lastOpenedAt = $row['lastOpenedAt'] !== null ? date('Y-m-d H:i:s', $row['lastOpenedAt']) : null;
                $sentiment = $row['replied'] ? $row['sentiment'] : null;
                $updateStmt->execute([$status, $row['currentStep'], $emailSent, $emailSentAt, $row['openCount'], $lastOpenedAt, $sentiment, $assignmentId]);
                $stats['matched']++;
            }
        }

        $stats['released'] += $this->releaseResolvedWaveLeaders($db, (int) $campaign['id']);
        $verifyResult = $this->refreshVerificationStatus($db, (int) $campaign['id']);
        $stats['verified'] += $verifyResult['checked'];
        $stats['verification_error'] = $verifyResult['error'];
        $db->prepare('UPDATE campaigns SET saleshandy_last_synced_at = NOW() WHERE id = ?')->execute([$campaign['id']]);

        return $stats;
    }

    /**
     * Re-checks Saleshandy's email verification status for every lead
     * already pushed in this campaign, piggybacking on the same "Refresh
     * statuses" action rather than a separate button -- verification only
     * becomes meaningful once a lead has actually been imported into
     * Saleshandy (checking before that point returns nothing useful), so
     * this naturally belongs alongside the existing post-push sync rather
     * than a pre-push check. Updates leads.email_verification_status
     * (raw string; classify via classifyVerificationTier() when
     * displaying) for every pushed lead in the campaign, not just ones
     * with fresh activity this sync -- so a status that changes on
     * Saleshandy's side after the fact (e.g. re-verified) still gets
     * picked up on the next refresh.
     *
     * @return array{checked:int, error:?string} error is the last
     *   SaleshandyApiException message hit, if any -- previously a
     *   failed chunk was silently skipped, so a genuinely broken call
     *   (wrong endpoint, auth, response shape) had no visible symptom:
     *   the flash message just never mentioned verification, identical
     *   to "nothing to check yet." Surfacing the error is the only way
     *   to tell "not verified yet on Saleshandy's side" (normal, quiet)
     *   apart from "this call is actually failing" (a real bug).
     */
    private function refreshVerificationStatus(PDO $db, int $campaignId): array
    {
        $rows = $db->prepare(
            "SELECT DISTINCT l.id, l.email FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
              WHERE a.campaign_id = ? AND a.status = 'pushed' AND l.deleted_at IS NULL"
        );
        $rows->execute([$campaignId]);
        $leads = $rows->fetchAll();
        if (!$leads) {
            return ['checked' => 0, 'error' => null];
        }

        $emailByLeadId = [];
        foreach ($leads as $lead) {
            $emailByLeadId[(int) $lead['id']] = $lead['email'];
        }

        $checked = 0;
        $error = null;
        $updateStmt = $db->prepare('UPDATE leads SET email_verification_status = ?, email_verification_checked_at = NOW() WHERE id = ?');
        foreach (array_chunk($emailByLeadId, 200, true) as $chunk) {
            try {
                $results = $this->checkVerificationStatus(array_values($chunk));
            } catch (SaleshandyApiException $ex) {
                $error = $ex->getMessage();
                continue; // best-effort -- a failed batch just leaves those leads' status as-is
            }
            foreach ($chunk as $leadId => $email) {
                $rawStatus = $results[strtolower($email)] ?? '';
                if ($rawStatus === '') {
                    continue; // not yet verified on Saleshandy's side -- leave existing value alone
                }
                $updateStmt->execute([$rawStatus, $leadId]);
                $checked++;
            }
        }

        return ['checked' => $checked, 'error' => $error];
    }

    /**
     * Releases every still-pending wave-1 leader in this campaign whose
     * stored delivery_status already shows Active/Replied/Paused --
     * whether that was set just now above or by an earlier sync, before
     * or after this auto-release feature existed. Pure local lookup, no
     * Saleshandy API call, and safely re-runnable (a leader that's
     * already resolved -- bounce_status != 'pending' -- simply won't
     * match again).
     */
    private function releaseResolvedWaveLeaders(PDO $db, int $campaignId): int
    {
        $stmt = $db->prepare(
            "SELECT a.id FROM lead_campaign_assignments a
              WHERE a.campaign_id = ? AND a.wave_leader_id IS NULL AND a.bounce_status = 'pending'
                AND a.delivery_status IN ('Active', 'Replied', 'Paused')
                AND EXISTS (SELECT 1 FROM lead_campaign_assignments h WHERE h.wave_leader_id = a.id AND h.wave_status = 'held')"
        );
        $stmt->execute([$campaignId]);
        $leaderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $released = 0;
        foreach ($leaderIds as $leaderId) {
            $released += WaveAssigner::release($db, $leaderId);
        }
        return $released;
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
                 saleshandy_current_step, saleshandy_synced_at, email_sent, email_sent_at, saleshandy_pushed_at)
             VALUES (?, ?, ?, 'pushed', ?, ?, ?, NOW(), ?, ?, ?)"
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
                : ($row['sentAt'] !== null ? 'Active' : 'Waiting')));
            $exportedAt = $row['sentAt'] !== null ? date('Y-m-d H:i:s', $row['sentAt']) : date('Y-m-d H:i:s');
            $emailSent = $row['sentAt'] !== null ? 1 : 0;
            $emailSentAt = $row['sentAt'] !== null ? date('Y-m-d', $row['sentAt']) : null;
            // We never actually pushed this one -- it was already enrolled
            // directly in Saleshandy -- so there's no true "first pushed"
            // event to record. Its earliest known send time is the closest
            // available proxy; NOW() (this pull-in moment) if not even
            // that is known, so the column isn't left misleadingly blank
            // for an entire class of leads.
            $pushedAt = $row['sentAt'] !== null ? date('Y-m-d H:i:s', $row['sentAt']) : date('Y-m-d H:i:s');

            $insertAssignment->execute([
                $leadId, $campaign['id'], $userId, $exportedAt, $deliveryStatus,
                $row['currentStep'], $emailSent, $emailSentAt, $pushedAt,
            ]);
            $stats['assignments_created']++;

            if ($row['bounced']) {
                WaveAssigner::suppressByEmail($db, $email, $userId, "Saleshandy pull-in: {$campaign['name']}", 'Bounced');
            }
        }

        return $stats;
    }

    /**
     * Runs both directions of the sync (syncCampaign() + pullNewProspects())
     * across every Saleshandy-linked campaign -- the exact combined loop
     * cron_saleshandy_sync.php runs on its schedule, extracted here so a
     * manual "Run now" button (saleshandy_sync_run.php) can trigger the
     * identical behavior on demand instead of only being reachable via a
     * cron hit. Returns both a human-readable summary line (for a flash
     * message / cron log line) and the per-campaign detail.
     *
     * @return array{summary:string,campaigns:array<int,array{name:string,ok:bool,detail:string}>}
     */
    public function syncAllLinkedCampaigns(PDO $db, int $userId): array
    {
        $campaigns = $db->query('SELECT * FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL')->fetchAll();
        $detail = [];
        $totalMatched = 0;
        $totalCreated = 0;
        $failures = 0;

        foreach ($campaigns as $campaign) {
            try {
                $syncStats = $this->syncCampaign($db, $campaign, $userId);
                $pullStats = $this->pullNewProspects($db, $campaign, $userId);
                $totalMatched += $syncStats['matched'];
                $totalCreated += $pullStats['leads_created'];
                $line = "{$syncStats['matched']} updated ({$syncStats['bounced']} bounced, {$syncStats['replied']} replied, "
                    . "{$syncStats['released']} wave-1 group(s) released, {$syncStats['verified']} verification status(es) refreshed); "
                    . "{$pullStats['leads_created']} new lead(s), {$pullStats['assignments_created']} new assignment(s) pulled in";
                if (!empty($syncStats['verification_error'])) {
                    $line .= " -- email verification check failed: {$syncStats['verification_error']}";
                }
                $detail[] = ['name' => $campaign['name'], 'ok' => true, 'detail' => $line];
            } catch (SaleshandyApiException $ex) {
                $failures++;
                $detail[] = ['name' => $campaign['name'], 'ok' => false, 'detail' => $ex->getMessage()];
            }
        }

        $summary = $campaigns
            ? "{$totalMatched} lead(s) updated, {$totalCreated} new lead(s) pulled in across " . count($campaigns) . ' campaign(s)'
                . ($failures ? ", {$failures} campaign(s) failed" : '')
            : 'No campaigns linked to Saleshandy -- nothing to sync.';

        return ['summary' => $summary, 'campaigns' => $detail];
    }

    /**
     * Repairs everything the normal "Refresh statuses" sync (syncCampaign())
     * can never reach once its narrow window has moved past a lead's actual
     * activity, because it only fetches events since saleshandy_last_synced_at
     * -- once that window has moved past a lead's actual send/delivery
     * event, the event never reappears in a later sync. For a campaign
     * that's been running a while, this can mean delivery_status stays
     * stuck at "Waiting" forever for leads that genuinely delivered, since
     * the regular sync's narrow window raced past the real event before it
     * was ever captured -- releaseResolvedWaveLeaders() only helps once
     * delivery_status is already correct, it has nothing to fall back on
     * if that value was never set right in the first place.
     *
     * Fixes, using the same full 2-year lookback:
     *  - delivery_status / saleshandy_current_step -- recomputed from the
     *    complete history the same way syncCampaign()'s per-row loop does
     *    (bounced > replied > unsubscribed > sent > waiting), always
     *    overwritten rather than only-if-empty, since this is the fuller,
     *    more authoritative picture. Bounces here also trigger the same
     *    domain-suppression side effect a regular sync would have.
     *  - email_sent_at stuck at the 1970-01-01 epoch bug (see
     *    parseSaleshandyDate()) from before that fix existed, or never set
     *    at all for a lead whose sync ran before Saleshandy actually had
     *    activity for it.
     *  - saleshandy_pushed_at left NULL for a lead pushed before sql/019
     *    added that column -- backfilled with its earliest known send time
     *    as a best-effort proxy (never overwrites a real value).
     *  - wave-1 leaders newly confirmed Active/Replied/Paused by the above
     *    get released the same way a regular sync's unconditional pass
     *    already does.
     *
     * Deliberately separate from the regular sync and manually triggered:
     * it re-fetches the FULL 2-year lookback (same as pullNewProspects()),
     * a much heavier call than the normal incremental one, meant to be run
     * once after upgrading (or whenever historical data looks wrong)
     * rather than on every "Refresh statuses" click.
     *
     * @param array<string,mixed> $campaign a row from `campaigns` (must have saleshandy_sequence_id)
     * @return array{checked:int, email_date_fixed:int, pushed_at_fixed:int, delivery_status_fixed:int, bounced:int, replied:int, released:int, opens_fixed:int, events_persisted:int}
     */
    public function backfillHistoricalDates(PDO $db, array $campaign, int $userId): array
    {
        $stats = [
            'checked' => 0, 'email_date_fixed' => 0, 'pushed_at_fixed' => 0,
            'delivery_status_fixed' => 0, 'bounced' => 0, 'replied' => 0, 'released' => 0, 'opens_fixed' => 0,
            'events_persisted' => 0,
        ];
        $sequenceId = $campaign['saleshandy_sequence_id'];
        if (!$sequenceId) {
            return $stats;
        }

        $startDate = date('Y-m-d', strtotime('-2 years'));
        $endDate = date('Y-m-d');
        $activity = $this->fetchSequenceActivity($sequenceId, $startDate, $endDate);
        $stats['events_persisted'] = $this->persistSendEvents($db, (int) $campaign['id'], $activity);
        $byEmail = $this->aggregateActivityByEmail($activity);
        if (!$byEmail) {
            return $stats;
        }

        $findStmt = $db->prepare(
            "SELECT a.id, a.email_sent_at, a.saleshandy_pushed_at, a.delivery_status, a.saleshandy_current_step,
                    a.open_count, a.last_opened_at, a.reply_sentiment
               FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
              WHERE a.campaign_id = ? AND l.email = ?"
        );
        $updateEmailDate = $db->prepare('UPDATE lead_campaign_assignments SET email_sent = 1, email_sent_at = ? WHERE id = ?');
        $updatePushedAt = $db->prepare('UPDATE lead_campaign_assignments SET saleshandy_pushed_at = ? WHERE id = ?');
        $updateDeliveryStatus = $db->prepare(
            'UPDATE lead_campaign_assignments
                SET delivery_status = ?, saleshandy_current_step = ?, saleshandy_synced_at = NOW(),
                    email_sent = email_sent OR ?, email_sent_at = COALESCE(email_sent_at, ?)
              WHERE id = ?'
        );
        $updateOpens = $db->prepare('UPDATE lead_campaign_assignments SET open_count = ?, last_opened_at = ?, reply_sentiment = ? WHERE id = ?');

        foreach ($byEmail as $email => $row) {
            if ($row['sentAt'] === null) {
                continue;
            }
            $findStmt->execute([$campaign['id'], $email]);
            $assignment = $findStmt->fetch();
            if (!$assignment) {
                continue; // activity for an email not assigned to this campaign locally
            }
            $stats['checked']++;

            if ($assignment['email_sent_at'] === null || $assignment['email_sent_at'] === '1970-01-01') {
                $updateEmailDate->execute([date('Y-m-d', $row['sentAt']), $assignment['id']]);
                $stats['email_date_fixed']++;
            }
            if ($assignment['saleshandy_pushed_at'] === null) {
                $updatePushedAt->execute([date('Y-m-d H:i:s', $row['sentAt']), $assignment['id']]);
                $stats['pushed_at_fixed']++;
            }

            $lastOpenedAt = $row['lastOpenedAt'] !== null ? date('Y-m-d H:i:s', $row['lastOpenedAt']) : null;
            $sentiment = $row['replied'] ? $row['sentiment'] : null;
            if ($row['openCount'] !== (int) $assignment['open_count'] || $lastOpenedAt !== $assignment['last_opened_at'] || $sentiment !== $assignment['reply_sentiment']) {
                $updateOpens->execute([$row['openCount'], $lastOpenedAt, $sentiment, $assignment['id']]);
                $stats['opens_fixed']++;
            }

            // Same priority as syncCampaign()'s per-row loop, but against
            // the full history rather than a possibly-stale narrow window
            // -- so this always overwrites, it's not a fill-if-empty guard.
            if ($row['bounced']) {
                $newStatus = 'Bounced';
                $stats['bounced']++;
                WaveAssigner::suppressByEmail($db, $email, $userId, "Saleshandy backfill: {$campaign['name']}", $newStatus);
            } elseif ($row['replied']) {
                $newStatus = 'Replied';
                $stats['replied']++;
            } elseif ($row['unsubscribed']) {
                $newStatus = 'Paused';
            } else {
                $newStatus = 'Active';
            }

            if ($newStatus !== $assignment['delivery_status'] || $row['currentStep'] !== $assignment['saleshandy_current_step']) {
                $emailSentAt = date('Y-m-d', $row['sentAt']);
                $updateDeliveryStatus->execute([$newStatus, $row['currentStep'], 1, $emailSentAt, $assignment['id']]);
                $stats['delivery_status_fixed']++;
            }
        }

        $stats['released'] += $this->releaseResolvedWaveLeaders($db, (int) $campaign['id']);

        return $stats;
    }

    /**
     * Pushes every push-eligible lead in a campaign to its linked
     * Saleshandy sequence step -- the shared implementation behind both
     * the manual "Push to Saleshandy" button (public/campaign_saleshandy_push.php,
     * a thin wrapper around this) and public/icp_distribution_cron.php's
     * optional auto-push. Extracted rather than duplicated so the two
     * callers can never drift out of sync with each other.
     *
     * Push-eligible: currently active under the wave-1 domain-safety gate
     * (not held, not suppressed), not already pushed, lead not
     * soft-deleted, and the lead's domain isn't on the suppression list.
     * Email verification: bad (invalid/undeliverable) addresses are never
     * sent to; risky ones are skipped unless $includeRisky is true.
     * Verification status is cached per lead so a re-push doesn't re-spend
     * verification credits on an address already checked.
     *
     * @param array<string,mixed> $campaign a row from `campaigns` (must have saleshandy_sequence_id/saleshandy_step_id)
     * @return array{pushed:int,skipped_bad:int,skipped_risky:int,stale_labels:array<int,string>,errors:array<int,string>,verification_check_error:?string}
     */
    public function pushCampaignLeads(PDO $db, array $campaign, bool $includeRisky = false): array
    {
        $result = ['pushed' => 0, 'skipped_bad' => 0, 'skipped_risky' => 0, 'stale_labels' => [], 'errors' => [], 'verification_check_error' => null];

        if (!$campaign['saleshandy_sequence_id'] || !$campaign['saleshandy_step_id']) {
            $result['errors'][] = 'This campaign is not linked to a Saleshandy sequence/step yet.';
            return $result;
        }

        $campaignId = (int) $campaign['id'];
        $rowsStmt = $db->prepare(
            "SELECT a.id AS assignment_id, l.*, v.label AS vertical_label, s.label AS service_label
               FROM lead_campaign_assignments a
               JOIN leads l ON l.id = a.lead_id
               LEFT JOIN verticals v ON v.id = l.vertical_id
               LEFT JOIN services s ON s.id = l.service_id
              WHERE a.campaign_id = ? AND a.wave_status = 'active' AND a.status != 'pushed'
                AND l.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1))"
        );
        $rowsStmt->execute([$campaignId]);
        $rows = $rowsStmt->fetchAll();

        if (!$rows) {
            return $result;
        }

        $leadsById = [];
        $assignmentIdByLead = [];
        foreach ($rows as $row) {
            $leadsById[(int) $row['id']] = $row;
            $assignmentIdByLead[(int) $row['id']] = (int) $row['assignment_id'];
        }

        $toVerify = [];
        foreach ($leadsById as $leadId => $lead) {
            if ($lead['email_verification_status'] === null) {
                $toVerify[$leadId] = $lead['email'];
            }
        }
        if ($toVerify) {
            try {
                $updateVerification = $db->prepare('UPDATE leads SET email_verification_status = ?, email_verification_checked_at = NOW() WHERE id = ?');
                foreach (array_chunk($toVerify, 200, true) as $chunk) {
                    $results = $this->checkVerificationStatus(array_values($chunk));
                    foreach ($chunk as $leadId => $email) {
                        $rawStatus = $results[strtolower($email)] ?? '';
                        $updateVerification->execute([$rawStatus !== '' ? $rawStatus : null, $leadId]);
                        $leadsById[$leadId]['email_verification_status'] = $rawStatus !== '' ? $rawStatus : null;
                    }
                }
            } catch (SaleshandyApiException $ex) {
                $result['verification_check_error'] = $ex->getMessage();
            }
        }

        foreach ($leadsById as $leadId => $lead) {
            $status = $lead['email_verification_status'];
            if ($status === null) {
                continue; // verification unavailable this run -- don't block on it
            }
            $tier = self::classifyVerificationTier($status);
            if ($tier === 'bad') {
                unset($leadsById[$leadId]);
                $result['skipped_bad']++;
            } elseif ($tier === 'risky' && !$includeRisky) {
                unset($leadsById[$leadId]);
                $result['skipped_risky']++;
            }
        }

        if (!$leadsById) {
            return $result;
        }

        $enabledMappings = $db->query(
            'SELECT lead_field_key, saleshandy_label FROM saleshandy_field_mappings WHERE enabled = 1'
        )->fetchAll();

        $staleLabels = [];
        try {
            $knownLabels = array_filter(array_map(
                static fn (array $f): string => trim((string) ($f['label'] ?? '')),
                $this->listFields()
            ));
            $enabledMappings = array_values(array_filter($enabledMappings, static function (array $m) use ($knownLabels, &$staleLabels) {
                if (in_array($m['saleshandy_label'], $knownLabels, true)) {
                    return true;
                }
                $staleLabels[] = $m['saleshandy_label'];
                return false;
            }));
        } catch (SaleshandyApiException $ex) {
            // Fail open -- push with mappings as configured, unvalidated.
        }
        $result['stale_labels'] = array_unique($staleLabels);

        $resolveValue = static function (array $lead, string $key): string {
            if ($key === 'vertical') {
                return (string) ($lead['vertical_label'] ?? '');
            }
            if ($key === 'service') {
                return (string) ($lead['service_label'] ?? '');
            }
            return (string) ($lead[$key] ?? '');
        };

        $buildProspect = static function (array $lead) use ($enabledMappings, $resolveValue): array {
            $prospect = [
                'First Name' => (string) $lead['first_name'],
                'Last Name' => (string) $lead['last_name'],
                'Email' => (string) $lead['email'],
            ];
            foreach ($enabledMappings as $m) {
                $value = $resolveValue($lead, $m['lead_field_key']);
                if ($value !== '') {
                    $prospect[$m['saleshandy_label']] = $value;
                }
            }
            return $prospect;
        };

        $groups = TagRepository::groupLeadsByTagSet($db, array_keys($leadsById));

        $updateStatus = $db->prepare(
            "UPDATE lead_campaign_assignments
                SET status = 'pushed', saleshandy_synced_at = NOW(), saleshandy_pushed_at = COALESCE(saleshandy_pushed_at, NOW())
              WHERE id = ?"
        );

        foreach ($groups as $group) {
            $prospectList = array_map(static fn (int $leadId): array => $buildProspect($leadsById[$leadId]), $group['lead_ids']);
            try {
                $this->pushProspects($campaign['saleshandy_step_id'], $prospectList, $group['tags']);
                foreach ($group['lead_ids'] as $leadId) {
                    $updateStatus->execute([$assignmentIdByLead[$leadId]]);
                }
                $result['pushed'] += count($group['lead_ids']);
            } catch (SaleshandyApiException $ex) {
                $result['errors'][] = $ex->getMessage();
            }
        }

        return $result;
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
     * Retries a request up to 3 extra times (1s, 2s, 4s backoff) if
     * Saleshandy responds with "Rate Limit exceeded" (error_code 4000,
     * seen in practice when a bulk action like syncFieldsToSaleshandy()
     * fires many requests back-to-back) -- Saleshandy's own CLI doesn't
     * document or handle a rate limit at all, so these numbers are a
     * conservative guess, not a confirmed spec. Any other error type
     * still fails immediately, unretried.
     *
     * @param array<string,mixed> $params query params (GET) or JSON body (POST/PUT)
     * @return array<string,mixed>
     */
    protected function request(string $method, string $path, array $params = []): array
    {
        $delayMicroseconds = 1_000_000;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->doRequest($method, $path, $params);
            } catch (SaleshandyRateLimitException $ex) {
                if ($attempt >= 4) {
                    throw $ex;
                }
                usleep($delayMicroseconds);
                $delayMicroseconds *= 2;
            }
        }
    }

    /**
     * @param array<string,mixed> $params query params (GET) or JSON body (POST/PUT)
     * @return array<string,mixed>
     */
    private function doRequest(string $method, string $path, array $params = []): array
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
            $fullMessage = "Saleshandy API error ({$httpCode}){$suffix}: {$message}";
            if ((int) $code === 4000 || stripos((string) $message, 'rate limit') !== false) {
                throw new SaleshandyRateLimitException($fullMessage);
            }
            throw new SaleshandyApiException($fullMessage);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

class SaleshandyApiException extends RuntimeException
{
}

/**
 * A SaleshandyApiException specifically for a rate-limited response --
 * caught and retried internally by request(); only escapes to callers
 * (still as a SaleshandyApiException, so existing catch blocks don't need
 * to change) if every retry attempt is also rate-limited.
 */
class SaleshandyRateLimitException extends SaleshandyApiException
{
}
