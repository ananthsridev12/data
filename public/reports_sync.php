<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reports.php');
    exit;
}

csrf_verify();

$campaignsStmt = db()->prepare('SELECT * FROM campaigns WHERE company_id = ? AND saleshandy_sequence_id IS NOT NULL');
$campaignsStmt->execute([(int) $admin['company_id']]);
$campaigns = $campaignsStmt->fetchAll();

if (!$campaigns) {
    flash_set('danger', 'No campaigns are linked to Saleshandy yet.');
    header('Location: reports.php');
    exit;
}

$matched = 0;
$errors = [];

foreach ($campaigns as $campaign) {
    try {
        $client = SaleshandyClient::forUser(db(), (int) $campaign['saleshandy_account_owner_id']);
        $stats = $client->syncCampaign(db(), $campaign, $admin['id']);
        $matched += $stats['matched'];
    } catch (SaleshandyApiException $ex) {
        $errors[] = "\"{$campaign['name']}\": {$ex->getMessage()}";
    }
}
flash_set('success', "Fetched from Saleshandy: {$matched} lead(s) updated across " . count($campaigns) . ' campaign(s).');
if ($errors) {
    flash_set('danger', 'Some campaigns failed to sync: ' . implode('; ', $errors));
}

header('Location: reports.php');
exit;
