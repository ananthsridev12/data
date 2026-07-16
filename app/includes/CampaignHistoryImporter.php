<?php

require_once __DIR__ . '/WaveAssigner.php';

/**
 * Backfills historical tracking data from a spreadsheet shaped like the
 * user's existing sheet: Email (required, matches an existing lead) plus
 * whichever of Vertical, Service, Campaign ID, Imported Saleshandy, Email
 * Sent, Email Date, Status are present. Every column is optional except
 * Email -- only the fields actually present in a given row are touched, so
 * a row with just Vertical/Service doesn't require a Campaign ID, and a
 * row with Campaign ID but no Imported/Email Sent/Status value just
 * creates/confirms the assignment without changing it.
 */
class CampaignHistoryImporter
{
    private const HEADER_ALIASES = [
        'email' => 'email',
        'vertical' => 'vertical',
        'service' => 'service',
        'campaign id' => 'campaign',
        'campaign' => 'campaign',
        'campaign name' => 'campaign',
        'imported saleshandy' => 'imported',
        'imported to saleshandy' => 'imported',
        'imported' => 'imported',
        'email sent' => 'email_sent',
        'sent' => 'email_sent',
        'email date' => 'email_date',
        'email sent date' => 'email_date',
        'date' => 'email_date',
        'status' => 'status',
        'delivery status' => 'status',
        'mail status' => 'status',
        'email status' => 'status',
        'lead status' => 'status',
    ];

    /**
     * @return array{
     *   processed:int, lead_not_found:int, vertical_updated:int, service_updated:int,
     *   campaigns_created:int, assignments_created:int, marked_imported:int, marked_email_sent:int,
     *   delivery_status_updated:int, bounces_processed:int,
     *   skipped_notes:array<int,string>
     * }
     */
    public static function import($handle, PDO $db, int $userId): array
    {
        $stats = [
            'processed' => 0, 'lead_not_found' => 0, 'vertical_updated' => 0, 'service_updated' => 0,
            'campaigns_created' => 0, 'assignments_created' => 0, 'marked_imported' => 0, 'marked_email_sent' => 0,
            'delivery_status_updated' => 0, 'bounces_processed' => 0,
            'skipped_notes' => [],
        ];

        $header = fgetcsv($handle);
        if ($header === false) {
            $stats['skipped_notes'][] = 'File appears to be empty.';
            return $stats;
        }

        $colMap = [];
        foreach ($header as $i => $col) {
            $normalized = strtolower(trim((string) $col));
            if (isset(self::HEADER_ALIASES[$normalized])) {
                $colMap[self::HEADER_ALIASES[$normalized]] = $i;
            }
        }

        if (!isset($colMap['email'])) {
            $stats['skipped_notes'][] = 'Could not find an "Email" column in that file.';
            return $stats;
        }

        $verticalMap = self::loadLookupMap($db, 'verticals');
        $serviceMap = self::loadLookupMap($db, 'services');
        $campaignIdCache = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $stats['processed']++;

            $email = strtolower(trim((string) ($row[$colMap['email']] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped_notes'][] = "Row {$rowNum}: not a valid email, skipped entirely.";
                continue;
            }

            $leadStmt = $db->prepare('SELECT id, vertical_id, service_id FROM leads WHERE email = ?');
            $leadStmt->execute([$email]);
            $lead = $leadStmt->fetch();
            if (!$lead) {
                $stats['lead_not_found']++;
                $stats['skipped_notes'][] = "Row {$rowNum} ({$email}): no matching lead found.";
                continue;
            }

            // Vertical / Service -- only touched if a non-empty value is present in this row.
            $verticalId = null;
            $serviceId = null;
            $touchVertical = false;
            $touchService = false;

            if (isset($colMap['vertical'])) {
                $raw = trim((string) ($row[$colMap['vertical']] ?? ''));
                if ($raw !== '') {
                    $touchVertical = true;
                    $verticalId = $verticalMap[mb_strtolower($raw)] ?? null;
                    if ($verticalId === null) {
                        $stats['skipped_notes'][] = "Row {$rowNum} ({$email}): Vertical \"{$raw}\" not found in Lists, left unchanged.";
                        $touchVertical = false;
                    }
                }
            }
            if (isset($colMap['service'])) {
                $raw = trim((string) ($row[$colMap['service']] ?? ''));
                if ($raw !== '') {
                    $touchService = true;
                    $serviceId = $serviceMap[mb_strtolower($raw)] ?? null;
                    if ($serviceId === null) {
                        $stats['skipped_notes'][] = "Row {$rowNum} ({$email}): Service \"{$raw}\" not found in Lists, left unchanged.";
                        $touchService = false;
                    }
                }
            }

            if ($touchVertical || $touchService) {
                $sets = [];
                $params = [];
                if ($touchVertical) {
                    $sets[] = 'vertical_id = ?';
                    $params[] = $verticalId;
                    $stats['vertical_updated']++;
                }
                if ($touchService) {
                    $sets[] = 'service_id = ?';
                    $params[] = $serviceId;
                    $stats['service_updated']++;
                }
                $params[] = $lead['id'];
                $db->prepare('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            }

            // Campaign-scoped fields (Imported Saleshandy / Email Sent / Email Date / Status) need a Campaign ID.
            $campaignName = isset($colMap['campaign']) ? trim((string) ($row[$colMap['campaign']] ?? '')) : '';
            $imported = isset($colMap['imported']) ? self::parseBool($row[$colMap['imported']] ?? '') : null;
            $emailSent = isset($colMap['email_sent']) ? self::parseBool($row[$colMap['email_sent']] ?? '') : null;
            $emailDate = isset($colMap['email_date']) ? self::parseDate($row[$colMap['email_date']] ?? '') : null;
            $deliveryStatus = null;
            if (isset($colMap['status'])) {
                $rawStatus = trim((string) ($row[$colMap['status']] ?? ''));
                if ($rawStatus !== '') {
                    $deliveryStatus = self::normalizeDeliveryStatus($rawStatus);
                    if ($deliveryStatus === null) {
                        $stats['skipped_notes'][] = "Row {$rowNum} ({$email}): Status \"{$rawStatus}\" not recognized, left unchanged.";
                    }
                }
            }

            if ($campaignName === '') {
                if ($imported !== null || $emailSent !== null || $deliveryStatus !== null) {
                    $stats['skipped_notes'][] = "Row {$rowNum} ({$email}): Imported/Email Sent/Status given without a Campaign ID, skipped.";
                }
                continue;
            }

            if (!isset($campaignIdCache[$campaignName])) {
                $campStmt = $db->prepare('SELECT id FROM campaigns WHERE name = ?');
                $campStmt->execute([$campaignName]);
                $campaignId = $campStmt->fetchColumn();
                if (!$campaignId) {
                    $db->prepare('INSERT INTO campaigns (name, created_by) VALUES (?, ?)')->execute([$campaignName, $userId]);
                    $campaignId = (int) $db->lastInsertId();
                    $stats['campaigns_created']++;
                }
                $campaignIdCache[$campaignName] = (int) $campaignId;
            }
            $campaignId = $campaignIdCache[$campaignName];

            $insertStmt = $db->prepare('INSERT IGNORE INTO lead_campaign_assignments (lead_id, campaign_id, assigned_by) VALUES (?, ?, ?)');
            $insertStmt->execute([$lead['id'], $campaignId, $userId]);
            if ($insertStmt->rowCount() === 1) {
                $assignmentId = (int) $db->lastInsertId();
                $stats['assignments_created']++;
            } else {
                $assignStmt = $db->prepare('SELECT id FROM lead_campaign_assignments WHERE lead_id = ? AND campaign_id = ?');
                $assignStmt->execute([$lead['id'], $campaignId]);
                $assignmentId = (int) $assignStmt->fetchColumn();
            }

            if ($imported === true) {
                $db->prepare("UPDATE lead_campaign_assignments SET status = 'exported', exported_at = COALESCE(exported_at, NOW()) WHERE id = ?")
                    ->execute([$assignmentId]);
                $stats['marked_imported']++;
            }
            if ($emailSent === true) {
                $db->prepare('UPDATE lead_campaign_assignments SET email_sent = 1, email_sent_at = COALESCE(?, email_sent_at) WHERE id = ?')
                    ->execute([$emailDate, $assignmentId]);
                $stats['marked_email_sent']++;
            }
            if ($deliveryStatus !== null) {
                $db->prepare('UPDATE lead_campaign_assignments SET delivery_status = ? WHERE id = ?')
                    ->execute([$deliveryStatus, $assignmentId]);
                $stats['delivery_status_updated']++;

                if (in_array($deliveryStatus, DELIVERY_STATUS_BOUNCE_VALUES, true)) {
                    WaveAssigner::suppressByEmail($db, $email, $userId, "Campaign history import: {$deliveryStatus}", $deliveryStatus);
                    $stats['bounces_processed']++;
                }
            }
        }

        return $stats;
    }

    private static function loadLookupMap(PDO $db, string $table): array
    {
        $map = [];
        foreach ($db->query("SELECT id, code, label FROM {$table} WHERE is_active = 1") as $row) {
            $map[mb_strtolower($row['code'])] = (int) $row['id'];
            $map[mb_strtolower($row['label'])] = (int) $row['id'];
        }
        return $map;
    }

    private static function normalizeDeliveryStatus(string $raw): ?string
    {
        $normalized = mb_strtolower(trim($raw));
        foreach (DELIVERY_STATUSES as $canonical) {
            if (mb_strtolower($canonical) === $normalized) {
                return $canonical;
            }
        }
        return null;
    }

    private static function parseBool($value): ?bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        return in_array($value, ['true', 'yes', 'y', '1'], true);
    }

    private static function parseDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }
}
