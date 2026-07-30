<?php
/**
 * Scheduled distributor for ICP segments -- processes ONE active ICP
 * (round-robin, IcpRepository::runDistributionForNext()) whose linked
 * campaigns' percentages sum to 100 per hit: finds newly-matching,
 * never-assigned leads, splits them by weighted percentage across the
 * linked campaigns, and assigns each split via WaveAssigner::assign()
 * (same domain-safety/wave-1 logic every other campaign assignment
 * uses). Optionally also pushes to Saleshandy immediately, per-ICP admin
 * choice (icp_segments.auto_push_enabled) -- which makes real API calls
 * per linked campaign, so processing one ICP per hit rather than
 * looping all of them keeps every hit fast. Run this fairly frequently
 * (every 5-15 minutes) so the full set of ICPs still cycles through in a
 * reasonable time. See app/includes/IcpRepository.php.
 *
 * Intended to be hit by a cPanel Cron Job (e.g.
 * `wget -q -O /dev/null "https://yoursite.com/icp_distribution_cron.php?token=..."`),
 * not a logged-in browser -- same shared-secret token auth as
 * cron_saleshandy_sync.php, reusing the same config value.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/IcpRepository.php';
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
    echo "No admin user found to attribute assignments to -- aborting.\n";
    exit;
}

$result = IcpRepository::runDistributionForNext(db(), $systemUserId);
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}
echo "\n{$result['summary']}\n";

CronRunLog::record(db(), 'icp_distribution', 'cron', $result['summary']);
