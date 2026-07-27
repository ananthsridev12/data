<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../vendor/simplexlsx/SimpleXLSX.php';
require_once __DIR__ . '/TagRepository.php';
require_once __DIR__ . '/RoleGroupClassifier.php';
require_once __DIR__ . '/EmployeeCountRangeClassifier.php';
require_once __DIR__ . '/CountryGroupClassifier.php';

use Shuchkin\SimpleXLSX;

/**
 * Reads .xlsx/.csv lead files and applies a confirmed header mapping to
 * upsert rows into `leads`. Row iteration always goes through a small
 * generator (readRawRows) so an .xlsx/.csv file is never loaded whole
 * into a PHP array -- important on shared hosting's tight memory limits.
 */
class LeadImporter
{
    /**
     * @return \Generator<int, array<int, string>> zero-based row index => list of raw cell strings
     */
    private static function readRawRows(string $path, string $fileType): \Generator
    {
        if ($fileType === 'csv') {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new RuntimeException('Could not open CSV file.');
            }
            // Strip a UTF-8 BOM if present so the first header isn't mangled.
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            try {
                while (($row = fgetcsv($handle)) !== false) {
                    if ($row === [null]) {
                        continue; // blank line
                    }
                    yield array_map(static fn($v) => $v === null ? '' : (string) $v, $row);
                }
            } finally {
                fclose($handle);
            }
            return;
        }

        if ($fileType === 'xlsx') {
            $xlsx = SimpleXLSX::parse($path);
            if ($xlsx === false) {
                throw new RuntimeException('Could not read XLSX file: ' . SimpleXLSX::parseError());
            }
            foreach ($xlsx->readRows() as $row) {
                if (implode('', $row) === '') {
                    continue; // fully blank row
                }
                yield array_map(static fn($v) => (string) $v, $row);
            }
            return;
        }

        throw new InvalidArgumentException("Unsupported file type: {$fileType}");
    }

    /**
     * @return array{headers: array<int,string>, samples: array<int,array<int,string>>}
     */
    public static function detectHeaderAndSamples(string $path, string $fileType, int $sampleCount = 5): array
    {
        $headers = [];
        $samples = [];

        foreach (self::readRawRows($path, $fileType) as $i => $row) {
            if ($i === 0) {
                $headers = $row;
                continue;
            }
            if (count($samples) < $sampleCount) {
                $samples[] = $row;
            } else {
                break;
            }
        }

        return ['headers' => $headers, 'samples' => $samples];
    }

    /**
     * Streams every data row (header excluded) into an NDJSON cache file,
     * recording the byte offset of each line so processChunk() can seek
     * directly to any row range without re-scanning the file from the start.
     *
     * @return int total data-row count written
     */
    public static function streamToCache(string $path, string $fileType, string $cachePath, string $offsetsPath): int
    {
        $cache = fopen($cachePath, 'w');
        if ($cache === false) {
            throw new RuntimeException('Could not open cache file for writing.');
        }

        $offsets = [];
        $total = 0;

        foreach (self::readRawRows($path, $fileType) as $i => $row) {
            if ($i === 0) {
                continue; // header
            }
            $offsets[] = ftell($cache);
            fwrite($cache, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
            $total++;
        }

        fclose($cache);
        file_put_contents($offsetsPath, json_encode($offsets));

        return $total;
    }

    /**
     * Processes rows [$offset, $offset + $limit) from the cache file: maps
     * each row via $mapping (header_key => target key, from
     * ImportMapper::suggestMapping()/a saved template), applies $defaults
     * for any target field left empty by the row (or never mapped from a
     * column at all), validates required fields + email format, and
     * upserts into `leads` keyed on email. Custom field values go to
     * `lead_custom_values` in the same pass, using the definitive lead id
     * from the upsert (see the `LAST_INSERT_ID(id)` trick below, needed
     * because plain `ON DUPLICATE KEY UPDATE` alone doesn't expose the
     * existing row's id via PDO::lastInsertId()).
     *
     * @param array<string,string|null> $mapping header_key => target key (LEAD_FIELDS/LOOKUP_FIELDS/custom field key, or null = ignored)
     * @param array<string,string> $defaults target key => default value, used when that field's resolved value is empty
     * @param ?int $assignCampaignId if set, every successfully imported/updated row in this chunk is also assigned to this campaign
     * @param ?int $assignedByUserId required if $assignCampaignId is set -- who to record as having made the assignment
     * @return array{processed:int, inserted:int, updated:int, skipped:int, errors:array<int,array{row_num:int,email:?string,reason:string}>}
     */
    public static function processChunk(
        PDO $db,
        int $batchId,
        string $sourcePath,
        string $fileType,
        string $cachePath,
        string $offsetsPath,
        array $mapping,
        int $offset,
        int $limit,
        array $defaults = [],
        ?int $assignCampaignId = null,
        ?int $assignedByUserId = null
    ): array {
        $headerAndSamples = self::detectHeaderAndSamples($sourcePath, $fileType, 0);
        $headerKeys = ImportMapper::buildHeaderKeys($headerAndSamples['headers']);

        $offsets = json_decode((string) file_get_contents($offsetsPath), true);
        if (!is_array($offsets)) {
            throw new RuntimeException('Could not read import offsets index.');
        }

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        if ($offset >= count($offsets)) {
            return $stats;
        }

        $cache = fopen($cachePath, 'r');
        if ($cache === false) {
            throw new RuntimeException('Could not open cache file for reading.');
        }
        fseek($cache, $offsets[$offset]);

        $lookupMaps = [];
        foreach (LOOKUP_FIELDS as $field => $meta) {
            $lookupMaps[$field] = self::loadLookupMap($db, $meta['table']);
        }
        $customFields = self::loadCustomFieldIds($db);
        // Ordered id ASC, same as lead_reclassify_roles.php -- first active
        // group with a keyword hit wins. Loaded once per chunk, not per
        // row, since role group rules don't change mid-import.
        $roleGroups = $db->query('SELECT id, keywords FROM role_groups WHERE is_active = 1 ORDER BY id')->fetchAll();
        $countryGroups = $db->query('SELECT id, countries FROM country_groups WHERE is_active = 1 ORDER BY id')->fetchAll();

        $columns = array_keys(LEAD_FIELDS);
        $extraColumns = array_map(static fn(array $f) => $f['fk_column'], LOOKUP_FIELDS);
        // role_group_id is deliberately NOT a LOOKUP_FIELDS entry -- an
        // unmatched title must never error the row the way an unrecognized
        // Vertical/Service value does (see RoleGroupClassifier's docblock),
        // it just classifies to null ("Unclassified"). employee_count_range
        // and country_group_id are the same idea applied to employee_count/
        // company_country -- derived, coarser buckets that never block the row.
        $allColumns = array_merge($columns, $extraColumns, ['role_group_id', 'employee_count_range', 'country_group_id']);
        $placeholders = implode(', ', array_fill(0, count($allColumns), '?'));
        $updateClause = implode(', ', array_map(
            static fn($c) => "{$c} = VALUES({$c})",
            array_filter(array_merge($allColumns, ['last_import_batch_id']), static fn($c) => $c !== 'email')
        ));
        // `id = LAST_INSERT_ID(id)` is a no-op value-wise, but makes
        // PDO::lastInsertId() return the existing row's id on an UPDATE
        // branch too (normally it only reflects fresh INSERTs), which we
        // need to attach custom field values to the right lead either way.
        $sql = 'INSERT INTO leads (' . implode(', ', $allColumns) . ', last_import_batch_id) VALUES (' . $placeholders . ', ?) '
            . "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), {$updateClause}";
        $stmt = $db->prepare($sql);

        $customStmt = $db->prepare(
            'INSERT INTO lead_custom_values (lead_id, custom_field_id, value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );

        $errStmt = $db->prepare(
            'INSERT INTO import_row_errors (import_batch_id, row_num, email, reason, raw_row_json) VALUES (?, ?, ?, ?, ?)'
        );

        // A lead may only ever belong to one campaign, and a suppressed
        // domain's leads may never be added to any -- so before
        // auto-assigning an imported row to $assignCampaignId, check it
        // isn't already assigned elsewhere and its domain isn't
        // suppressed (same rule WaveAssigner::filterEligibleForCampaign()
        // applies on the campaign-selection screen).
        $campaignAssignStmt = $assignCampaignId !== null
            ? $db->prepare('INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by) VALUES (?, ?, ?)')
            : null;
        $campaignElsewhereCheckStmt = $assignCampaignId !== null
            ? $db->prepare('SELECT 1 FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id != ? LIMIT 1')
            : null;
        $campaignSuppressedCheckStmt = $assignCampaignId !== null
            ? $db->prepare("SELECT 1 FROM suppressed_domains WHERE domain = SUBSTRING_INDEX(?, '@', -1) LIMIT 1")
            : null;

        $rowNum = $offset;
        for ($n = 0; $n < $limit; $n++) {
            $line = fgets($cache);
            if ($line === false) {
                break;
            }
            $rowNum++;
            $stats['processed']++;

            $rawRow = json_decode($line, true);
            if (!is_array($rawRow)) {
                continue;
            }

            $data = [];
            $lookupRaw = [];
            $customRaw = [];
            $tagsRaw = '';
            foreach ($headerKeys as $i => $key) {
                $col = $mapping[$key] ?? null;
                if ($col === null) {
                    continue;
                }
                $value = trim((string) ($rawRow[$i] ?? ''));
                if (isset(LEAD_FIELDS[$col])) {
                    $data[$col] = $value;
                } elseif (isset(LOOKUP_FIELDS[$col])) {
                    $lookupRaw[$col] = $value;
                } elseif (isset($customFields[$col])) {
                    $customRaw[$col] = $value;
                } elseif ($col === 'tags') {
                    $tagsRaw = $value;
                }
            }

            foreach ($defaults as $key => $defaultValue) {
                if ((string) $defaultValue === '') {
                    continue;
                }
                if (isset(LEAD_FIELDS[$key]) && empty($data[$key])) {
                    $data[$key] = $defaultValue;
                } elseif (isset(LOOKUP_FIELDS[$key]) && empty($lookupRaw[$key])) {
                    $lookupRaw[$key] = $defaultValue;
                } elseif (isset($customFields[$key]) && empty($customRaw[$key])) {
                    $customRaw[$key] = $defaultValue;
                }
            }

            // Tags accumulate rather than "default when empty" -- a batch-level
            // default tag list and a per-row mapped Tags column are both applied.
            $tagNames = array_filter(array_map('trim', preg_split('/[,;]/', $tagsRaw)));
            if (!empty($defaults['tags'])) {
                $tagNames = array_merge($tagNames, array_filter(array_map('trim', explode(',', $defaults['tags']))));
            }

            $missing = [];
            foreach (lead_required_fields() as $field) {
                if (empty($data[$field])) {
                    $missing[] = LEAD_FIELDS[$field]['label'];
                }
            }

            $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';

            $lookupIds = [];
            $unknownLookups = [];
            foreach (LOOKUP_FIELDS as $field => $meta) {
                $raw = $lookupRaw[$field] ?? '';
                if ($raw === '') {
                    $lookupIds[$meta['fk_column']] = null;
                    continue;
                }
                $normalized = mb_strtolower($raw);
                if (isset($lookupMaps[$field][$normalized])) {
                    $lookupIds[$meta['fk_column']] = $lookupMaps[$field][$normalized];
                } else {
                    $unknownLookups[] = "{$meta['label']} \"{$raw}\" (add it under Lists first)";
                }
            }

            if ($missing) {
                $reason = 'Missing required field(s): ' . implode(', ', $missing);
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reason = "Invalid email address: \"{$email}\"";
            } elseif ($unknownLookups) {
                $reason = 'Unrecognized value(s): ' . implode('; ', $unknownLookups);
            } else {
                $reason = null;
            }

            if ($reason !== null) {
                $stats['skipped']++;
                $errStmt->execute([$batchId, $rowNum, $email !== '' ? $email : null, $reason, json_encode($rawRow, JSON_UNESCAPED_UNICODE)]);
                $stats['errors'][] = ['row_num' => $rowNum, 'email' => $email ?: null, 'reason' => $reason];
                continue;
            }

            $data['email'] = $email;
            $values = [];
            foreach ($columns as $col) {
                $values[] = $data[$col] ?? null;
            }
            foreach ($extraColumns as $col) {
                $values[] = $lookupIds[$col] ?? null;
            }
            $values[] = RoleGroupClassifier::classify($data['title'] ?? null, $roleGroups);
            $values[] = EmployeeCountRangeClassifier::classify($data['employee_count'] ?? null);
            $values[] = CountryGroupClassifier::classify($data['company_country'] ?? null, $countryGroups);
            $values[] = $batchId;

            $stmt->execute($values);
            $affected = $stmt->rowCount();
            if ($affected === 1) {
                $stats['inserted']++;
            } else {
                $stats['updated']++;
            }

            $leadId = (int) $db->lastInsertId();

            if ($customRaw) {
                foreach ($customRaw as $fieldKey => $value) {
                    if ($value === '') {
                        continue;
                    }
                    $customStmt->execute([$leadId, $customFields[$fieldKey], $value]);
                }
            }

            if ($campaignAssignStmt !== null) {
                $campaignElsewhereCheckStmt->execute([$leadId, $assignCampaignId]);
                $campaignSuppressedCheckStmt->execute([$email]);
                if (!$campaignElsewhereCheckStmt->fetchColumn() && !$campaignSuppressedCheckStmt->fetchColumn()) {
                    $campaignAssignStmt->execute([$leadId, $assignCampaignId, $assignedByUserId]);
                }
            }

            if ($tagNames) {
                TagRepository::addTagsToLead($db, $leadId, $tagNames);
            }
        }

        fclose($cache);

        return $stats;
    }

    /**
     * @return array<string,int> field_key => id, for active custom fields only
     */
    private static function loadCustomFieldIds(PDO $db): array
    {
        $map = [];
        foreach ($db->query('SELECT id, field_key FROM custom_fields WHERE is_active = 1') as $row) {
            $map[$row['field_key']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * @return array<string,int> normalized(code or label) => id, for active rows only
     */
    private static function loadLookupMap(PDO $db, string $table): array
    {
        $map = [];
        $stmt = $db->query("SELECT id, code, label FROM {$table} WHERE is_active = 1");
        foreach ($stmt as $row) {
            $map[mb_strtolower($row['code'])] = (int) $row['id'];
            $map[mb_strtolower($row['label'])] = (int) $row['id'];
        }
        return $map;
    }
}
