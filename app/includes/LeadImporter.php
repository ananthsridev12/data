<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../vendor/simplexlsx/SimpleXLSX.php';

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
     * each row via $mapping (header_key => leads column, from
     * ImportMapper::suggestMapping()/a saved template), validates required
     * fields + email format, and upserts into `leads` keyed on email.
     *
     * @param array<string,string|null> $mapping header_key => leads column (or null = ignored)
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
        int $limit
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

        $columns = array_keys(LEAD_FIELDS);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updateClause = implode(', ', array_map(static fn($c) => "{$c} = VALUES({$c})", array_filter($columns, static fn($c) => $c !== 'email')));
        $sql = 'INSERT INTO leads (' . implode(', ', $columns) . ', last_import_batch_id) VALUES (' . $placeholders . ', ?) '
            . "ON DUPLICATE KEY UPDATE {$updateClause}, last_import_batch_id = VALUES(last_import_batch_id)";
        $stmt = $db->prepare($sql);

        $errStmt = $db->prepare(
            'INSERT INTO import_row_errors (import_batch_id, row_num, email, reason, raw_row_json) VALUES (?, ?, ?, ?, ?)'
        );

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
            foreach ($headerKeys as $i => $key) {
                $col = $mapping[$key] ?? null;
                if ($col !== null && isset(LEAD_FIELDS[$col])) {
                    $data[$col] = trim((string) ($rawRow[$i] ?? ''));
                }
            }

            $missing = [];
            foreach (lead_required_fields() as $field) {
                if (empty($data[$field])) {
                    $missing[] = LEAD_FIELDS[$field]['label'];
                }
            }

            $email = isset($data['email']) ? strtolower(trim($data['email'])) : '';

            if ($missing) {
                $reason = 'Missing required field(s): ' . implode(', ', $missing);
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $reason = "Invalid email address: \"{$email}\"";
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
            $values[] = $batchId;

            $stmt->execute($values);
            $affected = $stmt->rowCount();
            if ($affected === 1) {
                $stats['inserted']++;
            } else {
                $stats['updated']++;
            }
        }

        fclose($cache);

        return $stats;
    }
}
