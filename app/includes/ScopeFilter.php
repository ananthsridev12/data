<?php

require_once __DIR__ . '/Scope.php';

/**
 * Single place every company-scoped query routes its `company_id`
 * condition through, so no buildWhere()-style method can forget it.
 * Matches the $clauses/$params-array convention every repository's
 * buildWhere() already uses (see LeadRepository::buildWhere()) rather
 * than introducing a second query-building style.
 *
 * A query joining more than one scoped table (e.g. leads + campaigns)
 * must call apply() once per table alias with a distinct $paramName,
 * since PDO named placeholders are per-statement, not per-call.
 */
final class ScopeFilter
{
    public static function apply(
        array &$clauses,
        array &$params,
        Scope $scope,
        string $alias = 'l',
        string $paramName = 'scope_company_id'
    ): void {
        $clauses[] = "{$alias}.company_id = :{$paramName}";
        $params[$paramName] = $scope->companyId;
    }
}
