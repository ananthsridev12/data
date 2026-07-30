<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/ScopeFilter.php';

// Non-admins can view campaigns.php too now (their own / their team's
// campaigns) -- this endpoint returns only sequence active/inactive
// flags, nothing scoped/sensitive, so it stays available to any logged-
// in user rather than leaving their "Checking..." badges stuck forever.
$user = require_login();
$scope = Scope::fromUser(db(), $user);

header('Content-Type: application/json');

// Thin JSON wrapper around listSequences(), called async from
// campaigns.php after the page has already rendered -- keeps the live
// Saleshandy round-trip off the page's critical path entirely. Fails
// soft per-owner: a campaign whose owner isn't connected (or whose
// account errors) just leaves its badge as "Status unknown" rather than
// breaking every other campaign's badge.
//
// Each visible campaign lives in its own owning member's Saleshandy
// account (multiple accounts can be in play within one company now), so
// this de-dupes by owner and makes one listSequences() call per distinct
// connected owner instead of one shared global call.
$campaignClauses = ['c.company_id = :scope_company_id', 'c.saleshandy_sequence_id IS NOT NULL'];
$campaignParams = ['scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($campaignClauses, $campaignParams, $scope, db(), 'c', 'saleshandy_account_owner_id');
$ownerStmt = db()->prepare(
    'SELECT DISTINCT c.saleshandy_account_owner_id AS owner_id FROM campaigns c WHERE ' . implode(' AND ', $campaignClauses)
);
$ownerStmt->execute($campaignParams);
$ownerIds = array_map('intval', $ownerStmt->fetchAll(PDO::FETCH_COLUMN));

$active = [];
foreach ($ownerIds as $ownerId) {
    if (!$ownerId) {
        continue;
    }
    try {
        $client = SaleshandyClient::forUser(db(), $ownerId);
        foreach ($client->listSequences() as $seq) {
            if (isset($seq['id'])) {
                $active[$seq['id']] = (bool) ($seq['active'] ?? false);
            }
        }
    } catch (SaleshandyApiException $ex) {
        // Skipped -- that owner's campaigns just show "Status unknown".
    }
}

echo json_encode($active);
