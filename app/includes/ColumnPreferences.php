<?php

/**
 * Per-user, per-page column visibility + order for listing tables. Each
 * "page" has a fixed registry of available columns (below); a user's
 * saved preference is an ordered list of {key, visible} covering every
 * column currently known for that page -- if the code adds a new column
 * later, it's merged in (visible, at the end) the next time preferences
 * are loaded, and if a column is removed from the registry it's silently
 * dropped from stored preferences.
 */
class ColumnPreferences
{
    public const PAGES = [
        'dashboard' => [
            'company' => 'Company',
            'name' => 'Name',
            'title' => 'Title',
            'email' => 'Email',
            'industry' => 'Industry',
            'country' => 'Country',
            'seniority' => 'Seniority',
            'vertical' => 'Vertical',
            'service' => 'Service',
            'imported_by' => 'Imported by',
            'used_in' => 'Used in',
        ],
        'campaign_leads' => [
            'company' => 'Company',
            'contact' => 'Contact',
            'email' => 'Email',
            'wave' => 'Wave',
            'assigned' => 'Assigned',
            'imported' => 'Imported to Saleshandy',
            'email_sent' => 'Email Sent',
            'email_date' => 'Email Date',
        ],
    ];

    /**
     * @return array<int,array{key:string,label:string,visible:bool}>
     */
    public static function getForUser(PDO $db, int $userId, string $page): array
    {
        $registry = self::PAGES[$page] ?? [];

        $stmt = $db->prepare('SELECT columns_json FROM user_column_preferences WHERE user_id = ? AND page_key = ?');
        $stmt->execute([$userId, $page]);
        $saved = $stmt->fetchColumn();

        $ordered = [];
        $seen = [];

        if ($saved) {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    $key = $entry['key'] ?? null;
                    if ($key === null || !isset($registry[$key]) || isset($seen[$key])) {
                        continue; // column no longer exists in the registry, or a dup
                    }
                    $ordered[] = ['key' => $key, 'label' => $registry[$key], 'visible' => !empty($entry['visible'])];
                    $seen[$key] = true;
                }
            }
        }

        // Append any registry columns not present in the saved list (new columns), visible by default.
        foreach ($registry as $key => $label) {
            if (!isset($seen[$key])) {
                $ordered[] = ['key' => $key, 'label' => $label, 'visible' => true];
            }
        }

        return $ordered;
    }

    /**
     * @return array<string,bool> key => visible, for quick lookup when rendering
     */
    public static function visibilityMap(array $columns): array
    {
        $map = [];
        foreach ($columns as $col) {
            $map[$col['key']] = $col['visible'];
        }
        return $map;
    }

    public static function toggle(PDO $db, int $userId, string $page, string $key): void
    {
        $columns = self::getForUser($db, $userId, $page);
        foreach ($columns as &$col) {
            if ($col['key'] === $key) {
                $col['visible'] = !$col['visible'];
            }
        }
        unset($col);
        self::save($db, $userId, $page, $columns);
    }

    public static function move(PDO $db, int $userId, string $page, string $key, string $direction): void
    {
        $columns = self::getForUser($db, $userId, $page);
        $index = null;
        foreach ($columns as $i => $col) {
            if ($col['key'] === $key) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($columns)) {
            return;
        }
        [$columns[$index], $columns[$swapWith]] = [$columns[$swapWith], $columns[$index]];
        self::save($db, $userId, $page, $columns);
    }

    public static function resetToDefault(PDO $db, int $userId, string $page): void
    {
        $db->prepare('DELETE FROM user_column_preferences WHERE user_id = ? AND page_key = ?')->execute([$userId, $page]);
    }

    private static function save(PDO $db, int $userId, string $page, array $columns): void
    {
        $stored = array_map(static fn(array $c) => ['key' => $c['key'], 'visible' => $c['visible']], $columns);
        $json = json_encode($stored, JSON_UNESCAPED_UNICODE);
        $db->prepare(
            'INSERT INTO user_column_preferences (user_id, page_key, columns_json) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE columns_json = VALUES(columns_json)'
        )->execute([$userId, $page, $json]);
    }
}
