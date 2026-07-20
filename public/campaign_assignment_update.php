<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';
require_once __DIR__ . '/../app/includes/SaleshandyClient.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: campaigns.php');
    exit;
}

csrf_verify();

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$page = (int) ($_POST['page'] ?? 1);
$action = $_POST['action'] ?? '';
$ids = array_map('intval', $_POST['assignment_ids'] ?? []);

// Carries campaign_leads.php's active filters (if any) back into the
// redirect so a bulk action doesn't drop the admin back to the unfiltered
// view. Prefixed filter_* to avoid colliding with this endpoint's own
// same-named POST fields (e.g. delivery_status, the value to *apply*).
$filterParams = [];
foreach (['wave_status', 'email_sent', 'imported', 'delivery_status'] as $fk) {
    $fv = (string) ($_POST['filter_' . $fk] ?? '');
    if ($fv !== '') {
        $filterParams[$fk] = $fv;
    }
}
$redirect = 'campaign_leads.php?' . http_build_query(['campaign_id' => $campaignId, 'page' => $page] + $filterParams);

if (!$campaignId || !$ids) {
    flash_set('danger', 'No leads were selected.');
    header('Location: ' . $redirect);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

if ($action === 'mark_imported') {
    $stmt = db()->prepare(
        "UPDATE lead_campaign_assignments
            SET status = 'exported', exported_at = COALESCE(exported_at, NOW())
          WHERE campaign_id = ? AND id IN ({$placeholders}) AND status = 'assigned'"
    );
    $stmt->execute(array_merge([$campaignId], $ids));
    flash_set('success', $stmt->rowCount() . ' lead(s) marked as imported to Saleshandy.');
} elseif ($action === 'mark_email_sent') {
    $emailSentAt = $_POST['email_sent_at'] ?? '';
    $date = DateTime::createFromFormat('Y-m-d', $emailSentAt);
    if (!$date) {
        $emailSentAt = date('Y-m-d');
    }
    $stmt = db()->prepare(
        "UPDATE lead_campaign_assignments
            SET email_sent = 1, email_sent_at = ?
          WHERE campaign_id = ? AND id IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$emailSentAt, $campaignId], $ids));
    flash_set('success', $stmt->rowCount() . ' lead(s) marked as email sent (' . $emailSentAt . ').');
} elseif ($action === 'set_delivery_status') {
    $deliveryStatus = trim((string) ($_POST['delivery_status'] ?? ''));
    if (!in_array($deliveryStatus, DELIVERY_STATUSES, true)) {
        flash_set('danger', 'Please choose a valid delivery status.');
        header('Location: ' . $redirect);
        exit;
    }

    $stmt = db()->prepare(
        "UPDATE lead_campaign_assignments SET delivery_status = ? WHERE campaign_id = ? AND id IN ({$placeholders})"
    );
    $stmt->execute(array_merge([$deliveryStatus], [$campaignId], $ids));
    $updated = $stmt->rowCount();

    $suppressedCount = 0;
    if (in_array($deliveryStatus, DELIVERY_STATUS_BOUNCE_VALUES, true)) {
        $emailStmt = db()->prepare(
            "SELECT l.email FROM lead_campaign_assignments a JOIN leads l ON l.id = a.lead_id
              WHERE a.campaign_id = ? AND a.id IN ({$placeholders})"
        );
        $emailStmt->execute(array_merge([$campaignId], $ids));
        foreach ($emailStmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $result = WaveAssigner::suppressByEmail(db(), $email, $user['id'], "Delivery status: {$deliveryStatus}", $deliveryStatus);
            if ($result['suppressed']) {
                $suppressedCount++;
            }
        }
    }

    flash_set(
        'success',
        "{$updated} lead(s) marked \"{$deliveryStatus}\"" . ($suppressedCount > 0 ? " -- {$suppressedCount} domain(s) suppressed." : '.')
    );
} elseif ($action === 'remove_from_campaign') {
    // Only un-assigns leads not yet pushed to Saleshandy, and never a
    // wave-1 leader that still has a held group depending on it (deleting
    // that row would orphan the held leads with no leader to release/
    // suppress them via) -- both are silently skipped, counted below.
    $countStmt = db()->prepare(
        "SELECT COUNT(*) FROM lead_campaign_assignments a
          WHERE a.campaign_id = ? AND a.id IN ({$placeholders}) AND a.status = 'pushed'"
    );
    $countStmt->execute(array_merge([$campaignId], $ids));
    $pushedSkipped = (int) $countStmt->fetchColumn();

    $leaderIdsStmt = db()->prepare(
        "SELECT DISTINCT wave_leader_id FROM lead_campaign_assignments WHERE wave_leader_id IN ({$placeholders})"
    );
    $leaderIdsStmt->execute($ids);
    $protectedLeaderIds = array_map('intval', $leaderIdsStmt->fetchAll(PDO::FETCH_COLUMN));

    $removableIds = array_values(array_diff($ids, $protectedLeaderIds));
    $removed = 0;
    if ($removableIds) {
        $rPlaceholders = implode(',', array_fill(0, count($removableIds), '?'));
        $stmt = db()->prepare(
            "DELETE FROM lead_campaign_assignments WHERE campaign_id = ? AND id IN ({$rPlaceholders}) AND status != 'pushed'"
        );
        $stmt->execute(array_merge([$campaignId], $removableIds));
        $removed = $stmt->rowCount();
    }

    $message = "{$removed} lead(s) removed from this campaign -- they're free to be added to another campaign now.";
    if ($pushedSkipped > 0) {
        $message .= " {$pushedSkipped} skipped (already pushed to Saleshandy).";
    }
    if ($protectedLeaderIds) {
        $message .= ' ' . count($protectedLeaderIds) . ' skipped (still a wave-1 leader with a pending held group).';
    }
    flash_set('success', $message);
} elseif ($action === 'delete_lead') {
    if ($user['role'] !== ROLE_ADMIN) {
        flash_set('danger', 'Only admins can delete leads.');
        header('Location: ' . $redirect);
        exit;
    }

    $countStmt = db()->prepare(
        "SELECT COUNT(*) FROM lead_campaign_assignments WHERE campaign_id = ? AND id IN ({$placeholders}) AND status = 'pushed'"
    );
    $countStmt->execute(array_merge([$campaignId], $ids));
    $pushedSkipped = (int) $countStmt->fetchColumn();

    $leadIdsStmt = db()->prepare(
        "SELECT DISTINCT lead_id FROM lead_campaign_assignments WHERE campaign_id = ? AND id IN ({$placeholders}) AND status != 'pushed'"
    );
    $leadIdsStmt->execute(array_merge([$campaignId], $ids));
    $leadIds = array_map('intval', $leadIdsStmt->fetchAll(PDO::FETCH_COLUMN));

    $deleted = 0;
    if ($leadIds) {
        $leadPlaceholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = db()->prepare("UPDATE leads SET deleted_at = NOW(), deleted_by = ? WHERE id IN ({$leadPlaceholders})");
        $stmt->execute(array_merge([$user['id']], $leadIds));
        $deleted = $stmt->rowCount();
    }

    $message = "{$deleted} lead(s) deleted (hidden everywhere -- its campaign history is kept, and this can be undone from Deleted Leads).";
    if ($pushedSkipped > 0) {
        $message .= " {$pushedSkipped} skipped (already pushed to Saleshandy).";
    }
    flash_set('success', $message);
} elseif ($action === 'sync_fields_to_saleshandy') {
    $campStmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
    $campStmt->execute([$campaignId]);
    $campaign = $campStmt->fetch();
    if (!$campaign || !$campaign['saleshandy_sequence_id']) {
        flash_set('danger', 'This campaign is not linked to a Saleshandy sequence.');
        header('Location: ' . $redirect);
        exit;
    }

    $config = require __DIR__ . '/../app/config/config.php';
    try {
        $client = SaleshandyClient::fromConfig($config);
        $result = $client->syncFieldsToSaleshandy(db(), $ids, $campaignId);
    } catch (SaleshandyApiException $ex) {
        flash_set('danger', 'Could not reach Saleshandy: ' . $ex->getMessage());
        header('Location: ' . $redirect);
        exit;
    }

    $message = "{$result['updated']} lead(s) had every field successfully saved to their existing Saleshandy contact.";
    if ($result['partial'] > 0) {
        $message .= " {$result['partial']} had SOME fields rejected by Saleshandy (see errors below) -- not fully updated.";
    }
    if ($result['skipped_not_pushed'] > 0) {
        $message .= " {$result['skipped_not_pushed']} skipped (not pushed to Saleshandy yet).";
    }
    if ($result['not_found'] > 0) {
        $message .= " {$result['not_found']} skipped (no matching Saleshandy contact found by email).";
    }
    if ($result['no_fields'] > 0) {
        $message .= " {$result['no_fields']} had no enabled/mapped field with a value to send (check Saleshandy Field Mapping).";
    }
    if ($result['errors']) {
        $message .= ' Errors: ' . implode('; ', array_slice($result['errors'], 0, 3));
    }
    // "danger" whenever there's an actual failure reason to look at --
    // either explicit errors, or nothing succeeded and it wasn't simply
    // because every selected lead was skipped_not_pushed (that's a normal
    // filtering outcome, not a problem).
    $nothingSucceeded = $result['updated'] === 0 && $result['partial'] === 0;
    $hadAProblem = $result['errors'] || ($nothingSucceeded && ($result['not_found'] > 0 || $result['no_fields'] > 0));
    flash_set($hadAProblem ? 'danger' : 'success', $message);
} else {
    flash_set('danger', 'Unknown action.');
}

header('Location: ' . $redirect);
exit;
