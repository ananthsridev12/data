<?php

require_once __DIR__ . '/ScopeFilter.php';

/**
 * Loads a campaign scoped to the acting company and enforces row-level
 * visibility/mutation rights, consistent with every other owner-scoped
 * resource in this app (Scope::visibleOwnerIds() against
 * campaigns.saleshandy_account_owner_id): Admin sees and manages every
 * campaign in the company; Team Lead can VIEW their team's pooled
 * campaigns but only MUTATE (assign leads, push, configure, edit) ones
 * they personally own; Member can view/mutate only campaigns they
 * personally own. A campaign belonging to another company, or outside
 * the acting user's visible owner set, is treated identically to "not
 * found" -- a guessed id can never be used to confirm a campaign exists
 * elsewhere, or to distinguish "wrong company" from "not on your team".
 */
class CampaignAccess
{
    /** @return array|null the campaign row, or null if not found/not visible -- caller should flash + redirect */
    public static function loadVisible(PDO $db, Scope $scope, int $campaignId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM campaigns WHERE id = ? AND company_id = ?');
        $stmt->execute([$campaignId, $scope->companyId]);
        $campaign = $stmt->fetch();
        if (!$campaign) {
            return null;
        }
        if ($scope->isAdmin()) {
            return $campaign;
        }
        $visibleOwnerIds = $scope->visibleOwnerIds($db);
        if ($visibleOwnerIds !== null && !in_array((int) $campaign['saleshandy_account_owner_id'], $visibleOwnerIds, true)) {
            return null;
        }
        return $campaign;
    }

    /** Whether the acting Scope may mutate (assign leads to / push / edit / configure) this already-visible campaign, not just view it. */
    public static function canMutate(Scope $scope, array $campaign): bool
    {
        return $scope->isAdmin() || (int) $campaign['saleshandy_account_owner_id'] === $scope->userId;
    }
}
