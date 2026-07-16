<?php

/**
 * Streams `leads` rows as a Saleshandy-import-ready CSV directly to the
 * HTTP response, without buffering the full result set in memory.
 */
class CsvExporter
{
    public static function streamLeads(PDO $db, array $leadIds, string $filename): void
    {
        $map = require __DIR__ . '/../config/saleshandy_export_map.php';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fputcsv($out, array_values($map));

        if (!$leadIds) {
            fclose($out);
            return;
        }

        $columns = array_keys($map);
        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = $db->prepare(
            'SELECT ' . implode(', ', $columns) . " FROM leads WHERE id IN ({$placeholders}) ORDER BY id"
        );
        $stmt->execute($leadIds);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, array_values($row));
        }

        fclose($out);
    }
}
