<?php
/**
 * Automates "Backfill dates & status" across every Saleshandy-linked
 * campaign that hasn't been backfilled yet -- processes ONE campaign per
 * hit (SaleshandyClient::backfillNextCampaign(), round-robin), same
 * pattern as cron_saleshandy_sync.php / icp_distribution_cron.php.
 * Unlike those two, this naturally stops doing real work once every
 * linked campaign has been backfilled at least once (a fast no-op after
 * that) -- backfilling re-fetches a full 2-year window per campaign
 * (multiple paginated API calls), so it's meant to run once per
 * campaign, not repeatedly like the regular incremental sync.
 *
 * Run this less often than the sync/distribution crons (e.g. every
 * 30-60 minutes, not every 5) -- each unit of work here is heavier
 * (potentially many paginated API calls for one campaign's full
 * history), so spacing hits out further reduces rate-limit risk.
 *
 * Intended to be hit by a cPanel Cron Job (e.g.
 * `wget -q -O /dev/null "https://yoursite.com/campaign_saleshandy_backfill_cron.php?token=..."`),
 * not a logged-in browser -- same shared-secret token auth as the other
 * two cron scripts, reusing the same config value.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/CronRunLog.php';

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
    echo "No admin user found to attribute backfill actions to -- aborting.\n";
    exit;
}

$result = SaleshandyClient::backfillNextCampaign(db(), $systemUserId);
echo "{$result['summary']}\n";

CronRunLog::record(db(), 'saleshandy_backfill', 'cron', $result['summary']);
