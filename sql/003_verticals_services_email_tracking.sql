-- Adds admin-maintained Vertical/Service lookup lists (FK'd onto leads,
-- importable/filterable) and Email Sent tracking on campaign assignments.
-- Run via phpMyAdmin after 001_schema.sql / 002_seed_admin_user.sql.

CREATE TABLE verticals (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,
    label       VARCHAR(150) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_verticals_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,
    label       VARCHAR(150) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_services_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leads
    ADD COLUMN vertical_id INT UNSIGNED NULL AFTER category,
    ADD COLUMN service_id INT UNSIGNED NULL AFTER vertical_id,
    ADD CONSTRAINT fk_leads_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_leads_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    ADD KEY idx_leads_vertical (vertical_id),
    ADD KEY idx_leads_service (service_id);

ALTER TABLE lead_campaign_assignments
    ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER exported_at,
    ADD COLUMN email_sent_at DATE NULL AFTER email_sent;
