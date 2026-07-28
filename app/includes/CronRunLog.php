<?php

/**
 * Tiny run-history log for the two scheduled jobs (Saleshandy sync, ICP
 * distribution) -- see sql/029_cron_runs.sql. Both the token-authed cron
 * scripts and their manual "Run now" button counterparts record here, so
 * "did my cPanel Cron Job actually fire" has a real answer instead of
 * having to infer it from side effects.
 */
class CronRunLog
{
    public static function record(PDO $db, string $jobKey, string $triggeredBy, string $summary): void
    {
        $db->prepare('INSERT INTO cron_runs (job_key, triggered_by, summary) VALUES (?, ?, ?)')
            ->execute([$jobKey, $triggeredBy, $summary]);
    }

    /** @return array{triggered_by:string,ran_at:string,summary:?string}|null */
    public static function lastRun(PDO $db, string $jobKey): ?array
    {
        $stmt = $db->prepare('SELECT triggered_by, ran_at, summary FROM cron_runs WHERE job_key = ? ORDER BY ran_at DESC LIMIT 1');
        $stmt->execute([$jobKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
