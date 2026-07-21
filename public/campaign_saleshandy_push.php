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

// Email verification: bad (invalid/undeliverable) addresses are never
// sent to; risky ones are skipped by default and only included if the
// admin explicitly checked "Include risky emails" on the push form.
// Cached per lead (email_verification_status) so a re-push doesn't
// re-spend verification credits on an address already checked.
$includeRisky = !empty($_POST['include_risky']);
$toVerify = [];
foreach ($leadsById as $leadId => $lead) {
    if ($lead['email_verification_status'] === null) {
        $toVerify[$leadId] = $lead['email'];
    }
}
if ($toVerify) {
    try {
        $updateVerification = db()->prepare('UPDATE leads SET email_verification_status = ?, email_verification_checked_at = NOW() WHERE id = ?');
        foreach (array_chunk($toVerify, 200, true) as $chunk) {
            $results = $client->checkVerificationStatus(array_values($chunk));
            foreach ($chunk as $leadId => $email) {
                $rawStatus = $results[strtolower($email)] ?? '';
                $updateVerification->execute([$rawStatus !== '' ? $rawStatus : null, $leadId]);
                $leadsById[$leadId]['email_verification_status'] = $rawStatus !== '' ? $rawStatus : null;
            }
        }
    } catch (SaleshandyApiException $ex) {
        flash_set('info', 'Could not check email verification status (' . $ex->getMessage() . ') -- pushing without a verification filter this time.');
    }
}

$skippedBad = 0;
$skippedRisky = 0;
foreach ($leadsById as $leadId => $lead) {
    $status = $lead['email_verification_status'];
    if ($status === null) {
        continue; // verification unavailable this run -- don't block on it
    }
    $tier = SaleshandyClient::classifyVerificationTier($status);
    if ($tier === 'bad') {
        unset($leadsById[$leadId]);
        $skippedBad++;
    } elseif ($tier === 'risky' && !$includeRisky) {
        unset($leadsById[$leadId]);
        $skippedRisky++;
    }
}

if (!$leadsById) {
    flash_set('info', "No leads left to push after verification filtering ({$skippedBad} bad, {$skippedRisky} risky).");
    header('Location: ' . $redirect);
    exit;
}

$enabledMappings = db()->query(
    'SELECT lead_field_key, saleshandy_label FROM saleshandy_field_mappings WHERE enabled = 1'
)->fetchAll();

// A mapping's saleshandy_label must currently match one of Saleshandy's
// real field labels, or Saleshandy's import-with-field-name endpoint
// silently drops that key -- it doesn't error, it just doesn't show up
// on the pushed contact, which previously made a stale/mistyped label
// (e.g. one saved as free text before ever fetching the field list, or
// one that no longer exists in Saleshandy) impossible to tell apart from
// a real bug. Cross-check against a live fetch here so it's visible in
// the push result instead; if the fetch itself fails, fail open and
// push with the mappings as configured rather than blocking the push.
$staleLabels = [];
try {
    $knownLabels = array_filter(array_map(
        static fn(array $f) => trim((string) ($f['label'] ?? '')),
        $client->listFields()
    ));
    $enabledMappings = array_values(array_filter($enabledMappings, static function (array $m) use ($knownLabels, &$staleLabels) {
        if (in_array($m['saleshandy_label'], $knownLabels, true)) {
            return true;
        }
        $staleLabels[] = $m['saleshandy_label'];
        return false;
    }));
} catch (SaleshandyApiException $ex) {
    // Fail open -- push with mappings as configured, unvalidated.
}

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
// saleshandy_pushed_at is COALESCE-guarded so it always records the FIRST
// push, never a later re-push -- unlike saleshandy_synced_at (updated by
// every push/sync), it answers "when did this actually first go to
// Saleshandy" reliably.
$updateStatus = db()->prepare(
    "UPDATE lead_campaign_assignments
        SET status = 'pushed', saleshandy_synced_at = NOW(), saleshandy_pushed_at = COALESCE(saleshandy_pushed_at, NOW())
      WHERE id = ?"
);

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
    $verificationNote = ($skippedBad || $skippedRisky)
        ? " ({$skippedBad} skipped -- bad email" . ($skippedRisky ? ", {$skippedRisky} skipped -- risky email" : '') . ')'
        : '';
    flash_set('success', "{$pushedCount} lead(s) queued for push to Saleshandy{$verificationNote} -- run \"Refresh statuses\" in a few minutes to confirm delivery.");
}
if ($staleLabels) {
    flash_set(
        'danger',
        'These enabled field mapping(s) were NOT sent, because their Saleshandy label no longer matches a real field there -- '
            . 'fix them on Saleshandy Field Mapping (fetch the field list, then re-pick the label): '
            . implode(', ', array_unique($staleLabels))
    );
}
if ($errors) {
    flash_set('danger', 'Some pushes failed: ' . implode('; ', array_unique($errors)));
}

header('Location: ' . $redirect);
exit;
