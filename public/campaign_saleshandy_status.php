<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$admin = require_admin();

header('Content-Type: application/json');

// Thin JSON wrapper around listSequences(), called async from
// campaigns.php after the page has already rendered -- keeps the live
// Saleshandy round-trip off the page's critical path entirely. Fails
// soft: an empty object just leaves the "Checking..." badges as-is
// rather than breaking the page.
$config = require __DIR__ . '/../app/config/config.php';
try {
    $client = SaleshandyClient::fromConfig($config);
    $active = [];
    foreach ($client->listSequences() as $seq) {
        if (isset($seq['id'])) {
            $active[$seq['id']] = (bool) ($seq['active'] ?? false);
        }
    }
    echo json_encode($active);
} catch (SaleshandyApiException $ex) {
    echo json_encode([]);
}
