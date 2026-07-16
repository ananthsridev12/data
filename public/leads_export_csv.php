<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/LeadRepository.php';
require_once __DIR__ . '/../app/includes/CsvExporter.php';

$user = require_login();

// Export everything currently assigned to one campaign (stamps exported_at/status).
if (!empty($_GET['campaign_export'])) {
    $campaignId = (int) $_GET['campaign_export'];
    $campStmt = db()->prepare('SELECT id, name FROM campaigns WHERE id = ?');
    $campStmt->execute([$campaignId]);
    $campaign = $campStmt->fetch();

    if (!$campaign) {
        flash_set('danger', 'Campaign not found.');
        header('Location: campaigns.php');
        exit;
    }

    // Only 'active' assignments -- held (wave-1 pending) and suppressed
    // (wave-1 bounced) leads must never go out in a campaign export.
    $idsStmt = db()->prepare(
        "SELECT lead_id FROM lead_campaign_assignments WHERE campaign_id = ? AND wave_status = 'active' ORDER BY lead_id"
    );
    $idsStmt->execute([$campaignId]);
    $leadIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));

    $filename = 'saleshandy-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($campaign['name'])) . '-' . date('Ymd') . '.csv';
    CsvExporter::streamLeads(db(), $leadIds, $filename);

    db()->prepare(
        "UPDATE lead_campaign_assignments SET status = 'exported', exported_at = NOW() WHERE campaign_id = ? AND status = 'assigned'"
    )->execute([$campaignId]);
    exit;
}

// Ad-hoc export of everything matching the dashboard's current filter.
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'company' => trim((string) ($_GET['company'] ?? '')),
    'domain' => trim((string) ($_GET['domain'] ?? '')),
    'title' => trim((string) ($_GET['title'] ?? '')),
    'seniority' => trim((string) ($_GET['seniority'] ?? '')),
    'departments' => trim((string) ($_GET['departments'] ?? '')),
    'industry' => trim((string) ($_GET['industry'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'employee_count' => trim((string) ($_GET['employee_count'] ?? '')),
    'vertical_id' => trim((string) ($_GET['vertical_id'] ?? '')),
    'service_id' => trim((string) ($_GET['service_id'] ?? '')),
    'campaign_id' => trim((string) ($_GET['campaign_id'] ?? '')),
    'hide_used_in_campaign' => !empty($_GET['hide_used_in_campaign']),
    'show_suppressed' => !empty($_GET['show_suppressed']),
];

$leadIds = LeadRepository::matchingIds(db(), $filters);
CsvExporter::streamLeads(db(), $leadIds, 'leads-export-' . date('Ymd-His') . '.csv');
exit;
