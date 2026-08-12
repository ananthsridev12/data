-- Lets a member connect their own outbound-SMTP mailbox (same per-member
-- pattern as users.saleshandy_api_key -- see app/includes/SaleshandyKeyCipher.php
-- and public/saleshandy_connect.php) and build/send a custom campaign
-- report by email: pick which campaigns and which metric columns to
-- include, then send it through their own connected mailbox. No app-wide
-- SMTP config -- each user brings their own, so a send always goes out as
-- that user, through their own mail provider.

ALTER TABLE users
    ADD COLUMN smtp_host VARCHAR(255) NULL AFTER saleshandy_connected_at,
    ADD COLUMN smtp_port SMALLINT UNSIGNED NULL AFTER smtp_host,
    ADD COLUMN smtp_encryption ENUM('none', 'ssl', 'tls') NOT NULL DEFAULT 'tls' AFTER smtp_port,
    ADD COLUMN smtp_username VARCHAR(255) NULL AFTER smtp_encryption,
    ADD COLUMN smtp_password VARBINARY(512) NULL AFTER smtp_username,
    ADD COLUMN smtp_from_email VARCHAR(255) NULL AFTER smtp_password,
    ADD COLUMN smtp_from_name VARCHAR(190) NULL AFTER smtp_from_email,
    ADD COLUMN smtp_connected_at DATETIME NULL AFTER smtp_from_name;

-- A user's saved report definitions: which campaigns + which metric
-- columns (see EmailReportRepository::METRICS), so a recurring digest
-- doesn't need re-picking checkboxes every time it's sent.
CREATE TABLE email_reports (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id    INT UNSIGNED NOT NULL,
    created_by    INT UNSIGNED NOT NULL,
    name          VARCHAR(190) NOT NULL,
    campaign_ids  JSON NOT NULL,
    metrics       JSON NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_reports_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_reports_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_email_reports_company (company_id),
    KEY idx_email_reports_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
