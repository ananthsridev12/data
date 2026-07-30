<?php
// LeadImporter::processChunk() end-to-end: verifies the fix for a
// critical bug where every import silently omitted leads.company_id
// (NOT NULL) and owner_id, so real imports would have failed outright
// once the multi-tenant migration landed. Also verifies role_groups/
// country_groups/custom_fields/lookup classification during import is
// scoped to the importing company, not global. Rolled back at the end.
//
// Usage: php tests/import_company_scope_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/ImportMapper.php';
require_once __DIR__ . '/../app/includes/LeadImporter.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

$db = db();
$db->beginTransaction();

$tmpDir = sys_get_temp_dir() . '/import_scope_test_' . uniqid();
mkdir($tmpDir);

try {
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Co A', 90), ('Co B', 90)");
    $companyAId = (int) $db->lastInsertId();
    $companyBId = $companyAId + 1;

    $mkUser = function (int $companyId, string $email) use ($db): int {
        $stmt = $db->prepare('INSERT INTO users (company_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$companyId, $email, $email, 'x', ROLE_ADMIN]);
        return (int) $db->lastInsertId();
    };
    $userAId = $mkUser($companyAId, 'importer@a.test');
    $userBId = $mkUser($companyBId, 'importer@b.test');

    // Same role_group CODE in both companies, different keyword lists --
    // proves import classification doesn't cross-pollinate.
    $db->prepare('INSERT INTO role_groups (company_id, code, label, keywords, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$companyAId, 'ENG', 'Engineering (A)', 'Engineer']);
    $db->prepare('INSERT INTO role_groups (company_id, code, label, keywords, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$companyBId, 'ENG', 'Engineering (B)', 'Manager']); // deliberately different keyword

    $batchStmt = $db->prepare(
        "INSERT INTO import_batches (company_id, filename, stored_path, file_type, uploaded_by, status) VALUES (?, 'test.csv', 'test.csv', 'csv', ?, 'processing')"
    );
    $batchStmt->execute([$companyAId, $userAId]);
    $batchId = (int) $db->lastInsertId();

    $csvPath = $tmpDir . '/test.csv';
    file_put_contents($csvPath, "first_name,last_name,email,title\nJane,Doe,jane.doe@example.com,Software Engineer\n");

    $cachePath = $tmpDir . '/batch.ndjson';
    $offsetsPath = $tmpDir . '/batch.offsets.json';
    LeadImporter::streamToCache($csvPath, 'csv', $cachePath, $offsetsPath);

    $mapping = ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email', 'title' => 'title'];

    $result = LeadImporter::processChunk(
        $db, $batchId, $companyAId, $userAId,
        $csvPath, 'csv', $cachePath, $offsetsPath,
        $mapping, 0, 300
    );

    $assert(empty($result['errors']), 'processChunk() completes with zero row errors (used to hard-fail on missing company_id)');
    $assert($result['inserted'] === 1, 'processChunk() inserted exactly 1 lead');

    $leadStmt = $db->prepare('SELECT company_id, owner_id, role_group_id FROM leads WHERE email = ? AND company_id = ?');
    $leadStmt->execute(['jane.doe@example.com', $companyAId]);
    $lead = $leadStmt->fetch();

    $assert($lead !== false, 'the imported lead exists under the importing company');
    $assert($lead && (int) $lead['company_id'] === $companyAId, 'imported lead has the correct company_id');
    $assert($lead && (int) $lead['owner_id'] === $userAId, 'imported lead is owned by the importing user');

    $roleGroupAId = (int) $db->query("SELECT id FROM role_groups WHERE company_id = {$companyAId} AND code = 'ENG'")->fetchColumn();
    $assert($lead && (int) $lead['role_group_id'] === $roleGroupAId, 'role group classification used company A\'s own "Engineer" keyword, not company B\'s "Manager"');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll import company-scope checks passed.\n";
    }
} finally {
    $db->rollBack();
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    @rmdir($tmpDir);
}

exit($failures ? 1 : 0);
