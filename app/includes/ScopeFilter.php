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

    /**
     * Row-level "who can see this row" condition for an owner-style
     * column (leads.owner_id, campaigns.saleshandy_account_owner_id) --
     * Admin gets no added condition (unrestricted within the company
     * filter applied separately via apply()), Team Lead/Member get an
     * `{alias}.{column} IN (...)` restricted to Scope::visibleOwnerIds().
     * An empty visible-id list (e.g. a Team Lead not placed on any team)
     * renders as an always-false clause rather than an empty SQL `IN ()`,
     * which is invalid syntax.
     */
    public static function applyOwnerScope(
        array &$clauses,
        array &$params,
        Scope $scope,
        PDO $db,
        string $alias = 'l',
        string $ownerColumn = 'owner_id',
        string $paramPrefix = 'scope_owner'
    ): void {
        $ownerIds = $scope->visibleOwnerIds($db);
        if ($ownerIds === null) {
            return;
        }
        if (!$ownerIds) {
            $clauses[] = '1 = 0';
            return;
        }
        $placeholders = [];
        foreach ($ownerIds as $i => $id) {
            $key = "{$paramPrefix}_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }
        $clauses[] = "{$alias}.{$ownerColumn} IN (" . implode(',', $placeholders) . ')';
    }
}
