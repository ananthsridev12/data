-- Visibility into "did the scheduled job actually run" for the two cron
-- jobs this app relies on (Saleshandy sync, ICP distribution) -- neither
-- previously left any trace beyond side effects (campaigns.
-- saleshandy_last_synced_at, new lead_campaign_assignments rows), which
-- makes "is my cPanel Cron Job actually firing" hard to answer without
-- digging through data. One row per run, whether it came from the real
-- cron hit or a manual "Run now" button.

CREATE TABLE cron_runs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_key       VARCHAR(50) NOT NULL,
    triggered_by  ENUM('cron', 'manual') NOT NULL,
    ran_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    summary       TEXT NULL,
    KEY idx_cron_runs_job_ran (job_key, ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
