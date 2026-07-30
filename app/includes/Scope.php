<?php

/**
 * Per-request tenant/role context, built once from the logged-in user
 * and threaded explicitly into every repository method that touches a
 * company-scoped table (see ScopeFilter::apply()). There is no
 * "unscoped" constructor and no default company_id -- a call site that
 * doesn't have a real Scope yet must build one via fromUser(), never
 * fabricate/skip it, so a missing scope fails loudly (a PHP type error
 * on a repository call) instead of silently running an unfiltered query.
 */
final class Scope
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $teamId,
        public readonly int $leadCooldownDays
    ) {
    }

    /**
     * @param array{id:int,company_id:int,role:string,team_id:?int} $user as returned by current_user()
     */
    public static function fromUser(PDO $db, array $user): self
    {
        static $cooldownCache = [];

        $companyId = (int) $user['company_id'];
        if (!array_key_exists($companyId, $cooldownCache)) {
            $stmt = $db->prepare('SELECT lead_cooldown_days FROM companies WHERE id = ? LIMIT 1');
            $stmt->execute([$companyId]);
            $cooldownCache[$companyId] = (int) ($stmt->fetchColumn() ?: 90);
        }

        return new self(
            companyId: $companyId,
            userId: (int) $user['id'],
            role: $user['role'],
            teamId: $user['team_id'] !== null ? (int) $user['team_id'] : null,
            leadCooldownDays: $cooldownCache[$companyId]
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === ROLE_ADMIN;
    }

    public function isTeamLeadOrAbove(): bool
    {
        return $this->role === ROLE_ADMIN || $this->role === ROLE_TEAM_LEAD;
    }

    /**
     * Which user ids this Scope's row-level visibility rule covers, for an
     * "owner_id"/"saleshandy_account_owner_id"-style column (see
     * ScopeFilter::applyOwnerScope()) -- Admin sees every row in the
     * company (null = unrestricted), Team Lead sees their whole team
     * pooled together (including themselves), Member sees only their own.
     * Strict by design: a row owned by nobody on the visible list (e.g.
     * NULL owner_id, or owned by someone outside the team) is invisible,
     * with no "show unowned rows to everyone" fallback.
     *
     * @return int[]|null
     */
    public function visibleOwnerIds(PDO $db): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        if ($this->role === ROLE_TEAM_LEAD) {
            static $teamCache = [];
            if ($this->teamId === null) {
                // A Team Lead not actually placed on a team has no team to
                // pool -- falls back to "only their own" rather than
                // showing nothing or showing everyone.
                return [$this->userId];
            }
            $cacheKey = $this->companyId . ':' . $this->teamId;
            if (!array_key_exists($cacheKey, $teamCache)) {
                $stmt = $db->prepare('SELECT id FROM users WHERE team_id = ? AND company_id = ?');
                $stmt->execute([$this->teamId, $this->companyId]);
                $teamCache[$cacheKey] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            return $teamCache[$cacheKey];
        }

        return [$this->userId];
    }
}
