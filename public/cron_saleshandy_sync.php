<?php
/**
 * Scheduled backstop for pulling Saleshandy delivery/reply/bounce activity
 * into every linked campaign -- intended to be hit by a cPanel Cron Job
 * (e.g. `wget -q -O /dev/null "https://yoursite.com/cron_saleshandy_sync.php?token=..."`
 * every few hours), not a logged-in browser, so it authenticates via a
 * shared-secret token instead of a session. See README-DEPLOY.md.
 */
require_once __DIR__ . '/bootstrap.php';
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

// Attributed to the earliest-created admin, since a cron hit has no
// logged-in user of its own -- only used for WaveAssigner's audit trail
// (suppressed_domains.suppressed_by / lead_campaign_assignments.assigned_by).
$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$systemUserId) {
    echo "No admin user found to attribute sync actions to -- aborting.\n";
    exit;
}

try {
    $client = SaleshandyClient::fromConfig($config);
} catch (SaleshandyApiException $ex) {
    echo "Saleshandy not configured: {$ex->getMessage()}\n";
    exit;
}

$campaigns = db()->query('SELECT * FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL')->fetchAll();
if (!$campaigns) {
    echo "No campaigns linked to Saleshandy -- nothing to sync.\n";
    exit;
}

foreach ($campaigns as $campaign) {
    try {
        $stats = $client->syncCampaign(db(), $campaign, $systemUserId);
        echo "\"{$campaign['name']}\": {$stats['matched']} updated ({$stats['bounced']} bounced, {$stats['replied']} replied)\n";
    } catch (SaleshandyApiException $ex) {
        echo "\"{$campaign['name']}\": FAILED -- {$ex->getMessage()}\n";
    }
}
