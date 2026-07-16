-- Domain-level suppression list (global -- applies to every campaign and
-- every future import, not just the campaign a bounce was seen on) and
-- the wave-1-per-domain sending mechanics on lead_campaign_assignments.

CREATE TABLE suppressed_domains (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain         VARCHAR(255) NOT NULL,
    reason         VARCHAR(255) NULL,
    suppressed_by  INT UNSIGNED NOT NULL,
    suppressed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppressed_domain (domain),
    CONSTRAINT fk_suppressed_domains_user FOREIGN KEY (suppressed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lead_campaign_assignments
    ADD COLUMN wave_status ENUM('active', 'held', 'suppressed') NOT NULL DEFAULT 'active' AFTER status,
    ADD COLUMN wave_leader_id BIGINT UNSIGNED NULL AFTER wave_status,
    ADD COLUMN bounce_status ENUM('pending', 'delivered', 'bounced') NOT NULL DEFAULT 'pending' AFTER wave_leader_id,
    ADD CONSTRAINT fk_assignments_wave_leader FOREIGN KEY (wave_leader_id) REFERENCES lead_campaign_assignments(id) ON DELETE SET NULL,
    ADD KEY idx_assignments_wave_leader (wave_leader_id);
