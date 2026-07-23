<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: abm_report.php');
    exit;
}

csrf_verify();

$campaigns = db()->query("SELECT * FROM campaigns WHERE saleshandy_sequence_id IS NOT NULL")->fetchAll();

if (!$campaigns) {
    flash_set('danger', 'No campaigns are linked to Saleshandy yet.');
    header('Location: abm_report.php');
    exit;
}

$config = require __DIR__ . '/../app/config/config.php';
$matched = 0;
$errors = [];

try {
    $client = SaleshandyClient::fromConfig($config);
    foreach ($campaigns as $campaign) {
        try {
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
} catch (SaleshandyApiException $ex) {
    flash_set('danger', 'Could not connect to Saleshandy: ' . $ex->getMessage());
}

header('Location: abm_report.php');
exit;
