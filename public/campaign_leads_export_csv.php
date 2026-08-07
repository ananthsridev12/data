<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/CampaignAccess.php';

// Read-only reporting export of the campaign_leads.php filtered view --
// e.g. for taking clicked-a-link leads out to manually work their LinkedIn
// profile. Unlike leads_export_csv.php's campaign_export mode, this never
// touches status/exported_at.
$user = require_login();
$scope = Scope::fromUser(db(), $user);

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$campaign = CampaignAccess::loadVisible(db(), $scope, $campaignId);

if (!$campaign) {
    flash_set('danger', 'Campaign not found.');
    header('Location: campaigns.php');
    exit;
}

$filters = [
    'wave_status' => trim((string) ($_GET['wave_status'] ?? '')),
    'email_sent' => trim((string) ($_GET['email_sent'] ?? '')),
    'imported' => trim((string) ($_GET['imported'] ?? '')),
    'delivery_status' => trim((string) ($_GET['delivery_status'] ?? '')),
    'verification' => trim((string) ($_GET['verification'] ?? '')),
    'clicked' => trim((string) ($_GET['clicked'] ?? '')),
    'opened' => trim((string) ($_GET['opened'] ?? '')),
    'country_group_id' => trim((string) ($_GET['country_group_id'] ?? '')),
];

$sortableColumns = ['click_count' => 'a.click_count', 'open_count' => 'a.open_count', 'assigned_at' => 'a.assigned_at'];
$sort = isset($sortableColumns[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'click_count';
$dir = ($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
$orderBySql = $sortableColumns[$sort] . ' ' . $dir;

$whereClauses = ['a.campaign_id = :campaign_id', 'l.deleted_at IS NULL'];
$whereParams = ['campaign_id' => $campaignId];
if (in_array($filters['wave_status'], ['active', 'held', 'suppressed'], true)) {
    $whereClauses[] = 'a.wave_status = :wave_status';
    $whereParams['wave_status'] = $filters['wave_status'];
}
if ($filters['email_sent'] === '1' || $filters['email_sent'] === '0') {
    $whereClauses[] = 'a.email_sent = :email_sent';
    $whereParams['email_sent'] = (int) $filters['email_sent'];
}
if ($filters['imported'] === '1') {
    $whereClauses[] = "a.status IN ('exported', 'pushed')";
} elseif ($filters['imported'] === '0') {
    $whereClauses[] = "a.status = 'assigned'";
}
if ($filters['delivery_status'] !== '' && in_array($filters['delivery_status'], DELIVERY_STATUSES, true)) {
    $whereClauses[] = 'a.delivery_status = :delivery_status';
    $whereParams['delivery_status'] = $filters['delivery_status'];
}
$verificationBadSql = "l.email_verification_status REGEXP 'bad|invalid|undeliverable|reject|bounc|block|disposable'";
$verificationGoodSql = "l.email_verification_status REGEXP 'good|valid|deliverable|safe|verifi'";
if ($filters['verification'] === 'bad') {
    $whereClauses[] = $verificationBadSql;
} elseif ($filters['verification'] === 'good') {
    $whereClauses[] = "NOT ({$verificationBadSql}) AND {$verificationGoodSql}";
} elseif ($filters['verification'] === 'risky') {
    $whereClauses[] = "l.email_verification_status IS NOT NULL AND l.email_verification_status != '' AND NOT ({$verificationBadSql}) AND NOT ({$verificationGoodSql})";
} elseif ($filters['verification'] === 'none') {
    $whereClauses[] = "(l.email_verification_status IS NULL OR l.email_verification_status = '')";
}
$minCountValues = ['1', '2', '3', '5', '10'];
if ($filters['clicked'] === '0') {
    $whereClauses[] = 'a.click_count = 0';
} elseif (in_array($filters['clicked'], $minCountValues, true)) {
    $whereClauses[] = 'a.click_count >= :click_min';
    $whereParams['click_min'] = (int) $filters['clicked'];
}
if ($filters['opened'] === '0') {
    $whereClauses[] = 'a.open_count = 0';
} elseif (in_array($filters['opened'], $minCountValues, true)) {
    $whereClauses[] = 'a.open_count >= :open_min';
    $whereParams['open_min'] = (int) $filters['opened'];
}
if ($filters['country_group_id'] !== '') {
    $whereClauses[] = 'l.country_group_id = :country_group_id';
    $whereParams['country_group_id'] = (int) $filters['country_group_id'];
}

$stmt = db()->prepare(
    "SELECT l.na_company_name, l.first_name, l.last_name, l.email, l.person_linkedin_url,
            a.click_count, a.open_count, a.delivery_status, a.wave_status
       FROM lead_campaign_assignments a
       JOIN leads l ON l.id = a.lead_id
      WHERE " . implode(' AND ', $whereClauses) . "
      ORDER BY {$orderBySql}"
);
$stmt->execute($whereParams);

$filename = 'campaign-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($campaign['name'])) . '-leads-' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Company', 'First Name', 'Last Name', 'Email', 'LinkedIn URL', 'Link Clicks', 'Email Opens', 'Delivery Status', 'Wave Status']);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $row['na_company_name'],
        $row['first_name'],
        $row['last_name'],
        $row['email'],
        $row['person_linkedin_url'],
        $row['click_count'],
        $row['open_count'],
        $row['delivery_status'],
        $row['wave_status'],
    ]);
}
fclose($out);
exit;
