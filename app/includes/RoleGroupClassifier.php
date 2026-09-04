<?php

/**
 * Auto-classifies a lead's free-text `title` (and, per-group opt-in, its
 * `departments`/`sub_departments`) into one of the admin-defined
 * `role_groups` by ordered, case-insensitive substring keyword matching --
 * same matching style as WaveAssigner::pickLeader()'s title-priority list,
 * generalized here into a reusable, persisted classification instead of a
 * one-off runtime pick. Deliberately soft: an unmatched lead classifies to
 * null ("Unclassified") rather than erroring, since job titles/departments
 * are far too varied to pre-enumerate exhaustively the way a fixed
 * Vertical/Service list can be.
 *
 * $departments/$subDepartments default to null so every pre-existing
 * caller that only ever classified on title (before
 * sql/050_role_group_department_matching.sql) keeps working unchanged --
 * a group's match_departments/match_sub_departments flag only has
 * anything to check against once a caller actually passes those values.
 */
class RoleGroupClassifier
{
    /**
     * @param array<int,array{id:int,keywords:?string,match_departments?:mixed,match_sub_departments?:mixed}> $groups
     *   active role_groups rows, tried in the order given -- first group
     *   with a matching keyword wins (title checked first, then
     *   departments/sub_departments if that group opted in, so a title
     *   hit on a later-checked field never jumps the queue ahead of an
     *   earlier group's title hit). Caller controls ordering (e.g.
     *   `ORDER BY id`).
     */
    public static function classify(?string $title, array $groups, ?string $departments = null, ?string $subDepartments = null): ?int
    {
        if (($title === null || trim($title) === '')
            && ($departments === null || trim($departments) === '')
            && ($subDepartments === null || trim($subDepartments) === '')) {
            return null;
        }

        foreach ($groups as $group) {
            $keywords = self::parseKeywords($group['keywords'] ?? '');
            foreach ($keywords as $keyword) {
                if ($title !== null && stripos($title, $keyword) !== false) {
                    return (int) $group['id'];
                }
            }
            if (!empty($group['match_departments']) && $departments !== null) {
                foreach ($keywords as $keyword) {
                    if (stripos($departments, $keyword) !== false) {
                        return (int) $group['id'];
                    }
                }
            }
            if (!empty($group['match_sub_departments']) && $subDepartments !== null) {
                foreach ($keywords as $keyword) {
                    if (stripos($subDepartments, $keyword) !== false) {
                        return (int) $group['id'];
                    }
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
