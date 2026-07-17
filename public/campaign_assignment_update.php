<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/includes/WaveAssigner.php';

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
$redirect = 'campaign_leads.php?campaign_id=' . $campaignId . '&page=' . $page;

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
} else {
    flash_set('danger', 'Unknown action.');
}

header('Location: ' . $redirect);
exit;
