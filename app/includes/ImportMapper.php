<?php

require_once __DIR__ . '/../config/constants.php';

/**
 * Turns a spreadsheet's header row into a set of stable, de-duplicated
 * keys, and suggests a header -> `leads` column mapping the admin can
 * review/adjust on the import-mapping screen. Also matches a file's
 * header set against previously saved mapping templates.
 *
 * Mapping storage format (used for both `import_field_mappings.mapping_json`
 * and `import_batches.mapping_json`) is a single JSON object keyed by
 * header key, covering every header in the file (unmapped headers are
 * present with a null value):
 *   {"na company name": "na_company_name", "country": "country",
 *    "country#2": "company_country", "some unknown column": null, ...}
 * Storing the full header set (not just the mapped subset) lets us detect
 * an exact header-set match when looking up a reusable template.
 */
class ImportMapper
{
    public static function normalizeHeader(string $header): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($header));
        return mb_strtolower($normalized ?? '');
    }

    /**
     * Builds a unique key per header, disambiguating repeated header text
     * (e.g. "Country" appearing twice) by suffixing "#2", "#3", ... on
     * later occurrences. Index-aligned with the input $headers array.
     */
    public static function buildHeaderKeys(array $headers): array
    {
        $counts = [];
        $keys = [];

        foreach ($headers as $header) {
            $norm = self::normalizeHeader((string) $header);
            $counts[$norm] = ($counts[$norm] ?? 0) + 1;
            $keys[] = $counts[$norm] > 1 ? $norm . '#' . $counts[$norm] : $norm;
        }

        return $keys;
    }

    /**
     * Suggests a header_key -> leads column mapping for a fresh file with
     * no matching saved template. The first "country" header is suggested
     * as the person-level `country` column, the second as `company_country`
     * (see HEADER_ALIASES doc comment) -- the admin can override either on
     * the mapping screen.
     */
    public static function suggestMapping(array $headers): array
    {
        $keys = self::buildHeaderKeys($headers);
        $mapping = [];

        foreach ($keys as $key) {
            if ($key === 'country') {
                $mapping[$key] = 'country';
                continue;
            }
            if ($key === 'country#2') {
                $mapping[$key] = 'company_country';
                continue;
            }

            $base = preg_replace('/#\d+$/', '', $key);
            $mapping[$key] = HEADER_ALIASES[$base] ?? null;
        }

        return $mapping;
    }

    /**
     * Looks for a saved template whose header-key set exactly matches this
     * file's header keys. Returns the template row (with mapping decoded)
     * or null if none matches.
     */
    public static function findMatchingTemplate(PDO $db, array $headerKeys): ?array
    {
        $sortedNew = $headerKeys;
        sort($sortedNew);

        $stmt = $db->query('SELECT id, name, mapping_json FROM import_field_mappings ORDER BY last_used_at DESC, created_at DESC');

        foreach ($stmt as $row) {
            $decoded = json_decode($row['mapping_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $tplKeys = array_keys($decoded);
            sort($tplKeys);

            if ($tplKeys === $sortedNew) {
                return [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'mapping' => $decoded,
                ];
            }
        }

        return null;
    }
}
