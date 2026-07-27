<?php
/**
 * Scheduled distributor for ICP segments -- for each active ICP whose
 * linked campaigns' percentages sum to 100, finds newly-matching,
 * never-assigned leads, splits them by weighted percentage across the
 * linked campaigns, and assigns each split via WaveAssigner::assign()
 * (same domain-safety/wave-1 logic every other campaign assignment uses).
 * Optionally also pushes to Saleshandy immediately, per-ICP admin choice
 * (icp_segments.auto_push_enabled). See app/includes/IcpRepository.php.
 *
 * Intended to be hit by a cPanel Cron Job (e.g.
 * `wget -q -O /dev/null "https://yoursite.com/icp_distribution_cron.php?token=..."`
 * every few hours), not a logged-in browser -- same shared-secret token
 * auth as cron_saleshandy_sync.php, reusing the same config value.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

header('Content-Type: text/plain');

$config = require __DIR__ . '/../app/config/config.php';
$expectedToken = $config['saleshandy']['cron_token'] ?? '';
$givenToken = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, (string) $givenToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$systemUserId) {
    echo "No admin user found to attribute assignments to -- aborting.\n";
    exit;
}

$icps = IcpRepository::activeWithValidLinks(db());
if (!$icps) {
    echo "No active ICP segments with campaign links summing to 100% -- nothing to do.\n";
    exit;
}

$client = null;
try {
    $client = SaleshandyClient::fromConfig($config);
} catch (SaleshandyApiException $ex) {
    echo "Saleshandy not configured (auto-push ICPs will be skipped): {$ex->getMessage()}\n";
}

foreach ($icps as $icp) {
    try {
        $links = IcpRepository::links(db(), (int) $icp['id']);

        $filters = IcpRepository::toFilters($icp);
        $matchingIds = LeadRepository::matchingIds(db(), $filters);
        if (!$matchingIds) {
            echo "\"{$icp['name']}\": no new matching leads.\n";
            continue;
        }
        shuffle($matchingIds);

        $roleGroupStmt = db()->prepare('SELECT keywords FROM role_groups WHERE id = ?');
        $roleGroupStmt->execute([$icp['role_group_id']]);
        $titlePriority = RoleGroupClassifier::parseKeywords((string) $roleGroupStmt->fetchColumn());

        $buckets = IcpRepository::splitLeadIds($matchingIds, $links);

        echo "\"{$icp['name']}\": " . count($matchingIds) . ' matching lead(s) split across ' . count($links) . " campaign(s):\n";
        foreach ($links as $link) {
            $bucket = $buckets[(int) $link['campaign_id']] ?? [];
            if (!$bucket) {
                echo "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): 0 lead(s)\n";
                continue;
            }
            $stats = WaveAssigner::assign(db(), $bucket, (int) $link['campaign_id'], $systemUserId, $titlePriority);
            echo "  - \"{$link['campaign_name']}\" ({$link['percentage']}%): {$stats['leaders']} leader(s), {$stats['held']} held, "
                . "{$stats['suppressed_skipped']} suppressed, {$stats['already_elsewhere_skipped']} already elsewhere, "
                . "{$stats['pending_elsewhere_skipped']} pending elsewhere\n";

            if ($icp['auto_push_enabled'] && $client !== null) {
                $campStmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
                $campStmt->execute([(int) $link['campaign_id']]);
                $campaign = $campStmt->fetch();
                if ($campaign) {
                    try {
                        $pushResult = $client->pushCampaignLeads(db(), $campaign, false);
                        echo "    auto-push: {$pushResult['pushed']} pushed, {$pushResult['skipped_bad']} bad, {$pushResult['skipped_risky']} risky\n";
                        if ($pushResult['errors']) {
                            echo '    auto-push errors: ' . implode('; ', $pushResult['errors']) . "\n";
                        }
                    } catch (SaleshandyApiException $ex) {
                        echo "    auto-push FAILED: {$ex->getMessage()}\n";
                    }
                }
            }
        }
    } catch (Throwable $ex) {
        echo "\"{$icp['name']}\": FAILED -- {$ex->getMessage()}\n";
    }
}
