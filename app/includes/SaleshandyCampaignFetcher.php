<?php

require_once __DIR__ . '/SaleshandyClient.php';

/**
 * "Fetch from Saleshandy" -- lets a member browse the sequences already
 * sitting in their own connected Saleshandy account and pull selected
 * ones in as local campaigns, instead of creating a campaign shell by
 * hand and separately linking it. See public/campaign_saleshandy_fetch.php.
 *
 * Whose account can be browsed: always your own; Admin can additionally
 * browse any other company member who has connected a key (Admin
 * already has full company-wide reach everywhere else in this app).
 * Team Lead and Member are restricted to their own account only -- same
 * rule already applied to ICP Segments, since acting on another
 * member's Saleshandy account is a decision that member should make
 * themselves.
 */
class SaleshandyCampaignFetcher
{
    /** @return array<int,array{id:int,name:string,email:string}> */
    public static function browsableOwners(PDO $db, Scope $scope): array
    {
        if ($scope->isAdmin()) {
            $stmt = $db->prepare(
                'SELECT id, name, email FROM users
                  WHERE company_id = ? AND saleshandy_connected_at IS NOT NULL
                  ORDER BY name'
            );
            $stmt->execute([$scope->companyId]);
            return $stmt->fetchAll();
        }

        $stmt = $db->prepare(
            'SELECT id, name, email FROM users
              WHERE id = ? AND company_id = ? AND saleshandy_connected_at IS NOT NULL'
        );
        $stmt->execute([$scope->userId, $scope->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Every Saleshandy sequence id already linked to a campaign in this
     * company, mapped to that campaign's name -- lets the fetch screen
     * mark (or hide) sequences that already have a local campaign
     * instead of offering to create a duplicate.
     *
     * @return array<string,string> sequence_id => campaign name
     */
    public static function alreadyLinkedMap(PDO $db, int $companyId): array
    {
        $stmt = $db->prepare(
            'SELECT saleshandy_sequence_id, name FROM campaigns
              WHERE company_id = ? AND saleshandy_sequence_id IS NOT NULL'
        );
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['saleshandy_sequence_id']] = $row['name'];
        }
        return $map;
    }

    /**
     * Creates one local campaign mirroring an existing Saleshandy
     * sequence -- the step is auto-picked (sequence's first step, same
     * as campaign_saleshandy_settings.php's own "link a sequence" flow)
     * on a best-effort basis; a failed step lookup still leaves the
     * campaign linked (Refresh/Import work without a step, only Push
     * needs one). A name collision with an existing campaign in this
     * company is reported, not fatal to the rest of the batch.
     *
     * @return array{ok:bool,message:string}
     */
    public static function importSequence(
        PDO $db,
        SaleshandyClient $client,
        string $sequenceId,
        string $sequenceTitle,
        int $companyId,
        int $ownerId,
        int $createdBy
    ): array {
        $stepId = null;
        try {
            $steps = $client->listSequenceSteps($sequenceId);
            usort($steps, static fn(array $a, array $b) => ($a['number'] ?? 0) <=> ($b['number'] ?? 0));
            $stepId = $steps[0]['id'] ?? null;
        } catch (SaleshandyApiException $ex) {
            // Same fallback as campaign_saleshandy_settings.php -- the
            // campaign still gets created and linked below.
        }

        try {
            $db->prepare(
                'INSERT INTO campaigns (company_id, name, created_by, saleshandy_account_owner_id, saleshandy_sequence_id, saleshandy_step_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$companyId, $sequenceTitle, $createdBy, $ownerId, $sequenceId, $stepId]);
        } catch (PDOException $ex) {
            if (str_contains($ex->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'message' => "\"{$sequenceTitle}\": a campaign with that name already exists here."];
            }
            return ['ok' => false, 'message' => "\"{$sequenceTitle}\": could not create the campaign."];
        }

        return ['ok' => true, 'message' => "\"{$sequenceTitle}\" imported" . ($stepId ? '.' : ' (step lookup failed -- set one under "Change step" before using Push).')];
    }
}
