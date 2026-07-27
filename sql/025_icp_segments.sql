-- ICP (Ideal Customer Profile) segments: a named, admin-defined match rule
-- (company country, vertical, service, one buyer-persona role group, plus
-- a few more optional criteria) linked to 2+ campaigns with a percentage
-- weight each, for A/B-testing the same persona across campaigns. See
-- app/includes/IcpRepository.php and public/icp_distribution_cron.php.
--
-- One ICP = one persona (at most one role_group_id) -- to target multiple
-- personas, create multiple ICPs. company_country/industry/seniority/
-- employee_count are comma-separated multi-value lists, same convention
-- as role_groups.keywords (parsed with RoleGroupClassifier::parseKeywords()).

CREATE TABLE icp_segments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    role_group_id       INT UNSIGNED NULL,
    vertical_id         INT UNSIGNED NULL,
    service_id          INT UNSIGNED NULL,
    company_country     TEXT NULL,
    industry            TEXT NULL,
    seniority           TEXT NULL,
    employee_count      TEXT NULL,
    auto_push_enabled   TINYINT(1) NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_by          INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_icp_segments_name (name),
    CONSTRAINT fk_icp_segments_role_group FOREIGN KEY (role_group_id) REFERENCES role_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_icp_segments_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL,
    CONSTRAINT fk_icp_segments_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    CONSTRAINT fk_icp_segments_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Percentages across all rows for one icp_id must sum to exactly 100 --
-- enforced in application code (IcpRepository), not by the DB, same as
-- this codebase's other cross-row invariants (e.g. one-campaign-per-lead
-- is a WaveAssigner check, not a DB constraint).
CREATE TABLE icp_campaign_links (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    icp_id        INT UNSIGNED NOT NULL,
    campaign_id   INT UNSIGNED NOT NULL,
    percentage    TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_icp_campaign (icp_id, campaign_id),
    CONSTRAINT fk_icp_links_icp FOREIGN KEY (icp_id) REFERENCES icp_segments(id) ON DELETE CASCADE,
    CONSTRAINT fk_icp_links_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
