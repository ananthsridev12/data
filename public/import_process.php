<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/ImportMapper.php';
require_once __DIR__ . '/../app/includes/LeadImporter.php';
require_once __DIR__ . '/../app/includes/ScopeFilter.php';

$user = require_login();
$scope = Scope::fromUser(db(), $user);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

csrf_verify();

@ini_set('memory_limit', '256M');
@set_time_limit(0);

$batchId = (int) ($_POST['batch_id'] ?? 0);
$batchClauses = ['ib.id = :batch_id', 'ib.company_id = :scope_company_id'];
$batchParams = ['batch_id' => $batchId, 'scope_company_id' => $scope->companyId];
ScopeFilter::applyOwnerScope($batchClauses, $batchParams, $scope, db(), 'ib', 'uploaded_by');
$stmt = db()->prepare('SELECT ib.* FROM import_batches ib WHERE ' . implode(' AND ', $batchClauses));
$stmt->execute($batchParams);
$batch = $stmt->fetch();

if (!$batch) {
    http_response_code(404);
    echo json_encode(['error' => 'Batch not found']);
    exit;
}

if ($batch['status'] !== 'processing') {
    echo json_encode([
        'done' => true,
        'next_offset' => (int) $batch['next_offset'],
        'total_rows' => (int) $batch['total_rows'],
        'inserted_count' => (int) $batch['inserted_count'],
        'updated_count' => (int) $batch['updated_count'],
        'skipped_count' => (int) $batch['skipped_count'],
        'error_count' => (int) $batch['error_count'],
    ]);
    exit;
}

$config = require __DIR__ . '/../app/config/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');
$sourcePath = $uploadsDir . '/' . $batch['stored_path'];
$cachePath = $uploadsDir . '/cache/batch_' . $batchId . '.ndjson';
$offsetsPath = $uploadsDir . '/cache/batch_' . $batchId . '.offsets.json';
$stored = json_decode((string) $batch['mapping_json'], true) ?: [];
$mapping = $stored['mapping'] ?? $stored; // older batches stored a flat mapping with no "defaults" key
$defaults = $stored['defaults'] ?? [];
$assignCampaignId = isset($stored['assign_campaign_id']) ? (int) $stored['assign_campaign_id'] : null;

const IMPORT_CHUNK_SIZE = 300;

$result = LeadImporter::processChunk(
    db(),
    $batchId,
    (int) $batch['company_id'],
    (int) $batch['uploaded_by'],
    $sourcePath,
    $batch['file_type'],
    $cachePath,
    $offsetsPath,
    $mapping,
    (int) $batch['next_offset'],
    IMPORT_CHUNK_SIZE,
    $defaults,
    $assignCampaignId,
    $assignCampaignId !== null ? (int) $batch['uploaded_by'] : null
);

$nextOffset = (int) $batch['next_offset'] + $result['processed'];
$insertedCount = (int) $batch['inserted_count'] + $result['inserted'];
$updatedCount = (int) $batch['updated_count'] + $result['updated'];
$skippedCount = (int) $batch['skipped_count'] + $result['skipped'];
$errorCount = (int) $batch['error_count'] + count($result['errors']);

$done = $nextOffset >= (int) $batch['total_rows'];
$status = 'processing';
if ($done) {
    if ($errorCount > 0 && ($insertedCount + $updatedCount) === 0) {
        $status = 'failed';
    } elseif ($errorCount > 0) {
        $status = 'partial';
    } else {
        $status = 'completed';
    }
}

$update = db()->prepare(
    'UPDATE import_batches SET next_offset = ?, inserted_count = ?, updated_count = ?, skipped_count = ?, error_count = ?, status = ?, finished_at = ? WHERE id = ?'
);
$update->execute([
    $nextOffset,
    $insertedCount,
    $updatedCount,
    $skippedCount,
    $errorCount,
    $status,
    $done ? date('Y-m-d H:i:s') : null,
    $batchId,
]);

if ($done) {
    // Cache files are no longer needed once the batch is finished.
    @unlink($cachePath);
    @unlink($offsetsPath);
}

echo json_encode([
    'done' => $done,
    'next_offset' => $nextOffset,
    'total_rows' => (int) $batch['total_rows'],
    'inserted_count' => $insertedCount,
    'updated_count' => $updatedCount,
    'skipped_count' => $skippedCount,
    'error_count' => $errorCount,
]);
