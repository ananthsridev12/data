<?php
// WaveAssigner::listBounceTypes() -- the authoritative, per-company
// bounce type list every "Bounce Type" dropdown/validation allowlist
// should use, reading bounce_type_suppression_settings (the same list
// bounce_settings.php manages) rather than the narrower, stale
// WaveAssigner::BOUNCE_TYPES constant (5 values -- the real
// per-company-configurable list has grown to include Saleshandy's own
// raw bounce-report variants like "Hard Bounced"/"Bounced"/"All
// Bounced"/"Block Bounced"/"Soft Bounced", which weren't reflected in
// that constant). Falls back to the constant only for a company with
// nothing configured yet. Rolled back at the end.
//
// Usage: php tests/wave_assigner_list_bounce_types_test.php

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

$failures = [];
$assert = static function (bool $cond, string $label) use (&$failures): void {
    echo ($cond ? "PASS" : "FAIL") . " -- {$label}\n";
    if (!$cond) {
        $failures[] = $label;
    }
};

$db = db();
$db->beginTransaction();

try {
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('List Bounce Types Co', 0)");
    $companyId = (int) $db->lastInsertId();

    // --- No rows configured for this company at all: falls back to the
    // BOUNCE_TYPES constant, not an empty list.
    $fallback = WaveAssigner::listBounceTypes($db, $companyId);
    sort($fallback);
    $expectedFallback = WaveAssigner::BOUNCE_TYPES;
    sort($expectedFallback);
    $assert($fallback === $expectedFallback, 'With nothing configured, falls back to the BOUNCE_TYPES constant (got ' . json_encode($fallback) . ')');

    // --- Fully configured (mirrors sql/016's real seed set, including
    // the Saleshandy raw-export variants missing from the old constant).
    $insertType = $db->prepare('INSERT INTO bounce_type_suppression_settings (company_id, bounce_type, suppresses) VALUES (?, ?, 1)');
    $allTypes = ['Hard Bounce', 'Soft Bounce', 'Spam Complaint', 'Invalid Address', 'Other', 'Bounced', 'All Bounced', 'Hard Bounced', 'Soft Bounced', 'Block Bounced'];
    foreach ($allTypes as $t) {
        $insertType->execute([$companyId, $t]);
    }

    $full = WaveAssigner::listBounceTypes($db, $companyId);
    sort($full);
    $expectedFull = $allTypes;
    sort($expectedFull);
    $assert($full === $expectedFull, 'With all 10 configured, returns all 10 -- including the ones missing from the old BOUNCE_TYPES constant (got ' . json_encode($full) . ')');
    $assert(in_array('Hard Bounced', $full, true), '"Hard Bounced" (past-tense Saleshandy variant) is present -- was missing from the old hardcoded constant');
    $assert(in_array('Bounced', $full, true), '"Bounced" (the value WaveAssigner::suppressByEmail() itself hardcodes on pull-in) is present');

    // --- Company isolation: a second company with a different subset
    // doesn't see the first company's types, and vice versa.
    $db->exec("INSERT INTO companies (name, lead_cooldown_days) VALUES ('Other Co', 0)");
    $otherCompanyId = (int) $db->lastInsertId();
    $insertType->execute([$otherCompanyId, 'Hard Bounce']);
    $otherList = WaveAssigner::listBounceTypes($db, $otherCompanyId);
    $assert($otherList === ['Hard Bounce'], "Company B's list only has what Company B configured, not Company A's 10 (got " . json_encode($otherList) . ')');

    if ($failures) {
        echo "\n" . count($failures) . " FAILURE(S):\n";
        foreach ($failures as $f) {
            echo "  - {$f}\n";
        }
    } else {
        echo "\nAll WaveAssigner::listBounceTypes() checks passed.\n";
    }
} finally {
    $db->rollBack();
}

exit($failures ? 1 : 0);
