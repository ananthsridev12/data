<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';
require_once __DIR__ . '/../app/includes/TagRepository.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId;

$stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

if (!$campaign['saleshandy_sequence_id'] || !$campaign['saleshandy_step_id']) {
    flash_set('danger', 'This campaign is not linked to a Saleshandy sequence/step yet -- use "Configure" on the Campaigns page first.');
    header('Location: ' . $redirect);
    exit;
}

$config = require __DIR__ . '/../app/config/config.php';
try {
    $client = SaleshandyClient::fromConfig($config);
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not reach Saleshandy: ' . $ex->getMessage());
    header('Location: ' . $redirect);
    exit;
}

// Push-eligible: currently active under the wave-1 domain-safety gate (not
// held, not suppressed), not already pushed, lead not soft-deleted, and
// the lead's domain isn't on the suppression list.
$rowsStmt = db()->prepare(
    "SELECT a.id AS assignment_id, l.*, v.label AS vertical_label, s.label AS service_label
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
       LEFT JOIN verticals v ON v.id = l.vertical_id
       LEFT JOIN services s ON s.id = l.service_id
      WHERE a.campaign_id = ? AND a.wave_status = 'active' AND a.status != 'pushed'
        AND l.deleted_at IS NULL
        AND NOT EXISTS (SELECT 1 FROM suppressed_domains sd WHERE sd.domain = SUBSTRING_INDEX(l.email, '@', -1))"
);
$rowsStmt->execute([$campaignId]);
$rows = $rowsStmt->fetchAll();

if (!$rows) {
    flash_set('info', 'No push-eligible leads for this campaign right now (already pushed, held for wave-1, or suppressed).');
    header('Location: ' . $redirect);
    exit;
}

$leadsById = [];
$assignmentIdByLead = [];
foreach ($rows as $row) {
    $leadsById[(int) $row['id']] = $row;
    $assignmentIdByLead[(int) $row['id']] = (int) $row['assignment_id'];
}

$enabledMappings = db()->query(
    'SELECT lead_field_key, saleshandy_label FROM saleshandy_field_mappings WHERE enabled = 1'
)->fetchAll();

$resolveValue = static function (array $lead, string $key): string {
    if ($key === 'vertical') {
        return (string) ($lead['vertical_label'] ?? '');
    }
    if ($key === 'service') {
        return (string) ($lead['service_label'] ?? '');
    }
    return (string) ($lead[$key] ?? '');
};

$buildProspect = static function (array $lead) use ($enabledMappings, $resolveValue): array {
    $prospect = [
        'First Name' => (string) $lead['first_name'],
        'Last Name' => (string) $lead['last_name'],
        'Email' => (string) $lead['email'],
    ];
    foreach ($enabledMappings as $m) {
        $value = $resolveValue($lead, $m['lead_field_key']);
        if ($value !== '') {
            $prospect[$m['saleshandy_label']] = $value;
        }
    }
    return $prospect;
};

$groups = TagRepository::groupLeadsByTagSet(db(), array_keys($leadsById));

$pushedCount = 0;
$errors = [];
$updateStatus = db()->prepare('UPDATE lead_campaign_assignments SET status = \'pushed\' WHERE id = ?');

foreach ($groups as $group) {
    $prospectList = array_map(static fn(int $leadId) => $buildProspect($leadsById[$leadId]), $group['lead_ids']);
    try {
        $client->pushProspects($campaign['saleshandy_step_id'], $prospectList, $group['tags']);
        foreach ($group['lead_ids'] as $leadId) {
            $updateStatus->execute([$assignmentIdByLead[$leadId]]);
        }
        $pushedCount += count($group['lead_ids']);
    } catch (SaleshandyApiException $ex) {
        $errors[] = $ex->getMessage();
    }
}

if ($pushedCount > 0) {
    flash_set('success', "{$pushedCount} lead(s) queued for push to Saleshandy -- run \"Refresh statuses\" in a few minutes to confirm delivery.");
}
if ($errors) {
    flash_set('danger', 'Some pushes failed: ' . implode('; ', array_unique($errors)));
}

header('Location: ' . $redirect);
exit;
