-- Role Groups: consolidates raw free-text lead titles into a small set of
-- named, admin-managed targeting buckets (e.g. "Engineering Leadership",
-- "IT Ops"), each with a comma-separated, ordered, case-insensitive
-- substring keyword list used to auto-classify leads by their `title`.
-- Same shape as verticals/services (003_verticals_services_email_tracking.sql)
-- plus one extra `keywords` column. Run via phpMyAdmin/cPanel after the
-- prior migrations.

CREATE TABLE role_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,
    label       VARCHAR(150) NOT NULL,
    keywords    TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_groups_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leads
    ADD COLUMN role_group_id INT UNSIGNED NULL AFTER service_id,
    ADD CONSTRAINT fk_leads_role_group FOREIGN KEY (role_group_id) REFERENCES role_groups(id) ON DELETE SET NULL,
    ADD KEY idx_leads_role_group (role_group_id);
