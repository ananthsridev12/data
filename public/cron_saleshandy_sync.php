<?php
/**
 * Scheduled backstop for both directions of the Saleshandy sync -- pulling
 * delivery/reply/bounce activity into a linked campaign's already-
 * assigned leads (syncCampaign) AND pulling in prospects that are enrolled
 * in Saleshandy but don't exist here yet (pullNewProspects). Processes
 * ONE campaign per hit (SaleshandyClient::syncNextCampaign(), round-robin
 * by least-recently-attempted) rather than looping every linked campaign
 * in one request -- with 15+ campaigns each risking a 30s API timeout,
 * looping them all in a single request can take minutes and risks
 * hitting PHP's max_execution_time or the web server's own request
 * timeout. Run this frequently (every 5-10 minutes, not every few hours)
 * so the full set of linked campaigns still cycles through in a
 * reasonable time -- see README-DEPLOY.md.
 *
 * Intended to be hit by a cPanel Cron Job (e.g.
 * `wget -q -O /dev/null "https://yoursite.com/cron_saleshandy_sync.php?token=..."`),
 * not a logged-in browser, so it authenticates via a shared-secret token
 * instead of a session.
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

// Attributed to the earliest-created admin, since a cron hit has no
// logged-in user of its own -- only used for WaveAssigner's audit trail
// (suppressed_domains.suppressed_by / lead_campaign_assignments.assigned_by).
$systemUserId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if (!$systemUserId) {
    echo "No admin user found to attribute sync actions to -- aborting.\n";
    exit;
}

$result = SaleshandyClient::syncNextCampaign(db(), $systemUserId);
echo "{$result['summary']}\n";

CronRunLog::record(db(), 'saleshandy_sync', 'cron', $result['summary']);
