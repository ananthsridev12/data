<?php

/**
 * Auto-classifies a lead's free-text `title` into one of the admin-defined
 * `role_groups` by ordered, case-insensitive substring keyword matching --
 * same matching style as WaveAssigner::pickLeader()'s title-priority list,
 * generalized here into a reusable, persisted classification instead of a
 * one-off runtime pick. Deliberately soft: an unmatched title classifies to
 * null ("Unclassified") rather than erroring, since job titles are far too
 * varied to pre-enumerate exhaustively the way a fixed Vertical/Service
 * list can be.
 */
class RoleGroupClassifier
{
    /**
     * @param array<int,array{id:int,keywords:?string}> $groups active role_groups
     *   rows, tried in the order given -- first group with a matching
     *   keyword wins. Caller controls ordering (e.g. `ORDER BY id`).
     */
    public static function classify(?string $title, array $groups): ?int
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        foreach ($groups as $group) {
            foreach (self::parseKeywords($group['keywords'] ?? '') as $keyword) {
                if (stripos($title, $keyword) !== false) {
                    return (int) $group['id'];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string> trimmed, non-empty keywords
     */
    public static function parseKeywords(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $k) => $k !== ''));
    }
}
