-- Country Groups: admin-defined regional/timezone buckets for
-- leads.company_country (e.g. "Americas", "UK, Ireland & Europe"), so
-- filtering/targeting can work at a region level instead of one
-- checkbox per exact country string. Mirrors the Role Groups pattern
-- (sql/021_role_groups.sql) structurally, but with EXACT (not
-- substring) matching -- see app/includes/CountryGroupClassifier.php --
-- since country names are standardized enough that substring matching
-- would risk over-matching short codes.
--
-- Seeded with a starting set of 4 groups per the user's own description;
-- fully editable afterward via country_groups.php. Every leads.company_country
-- value that doesn't exactly match a group's country list stays
-- unclassified (NULL) -- surfaced on country_groups.php as an "unmapped
-- countries" list for one-click assignment, same as Role Groups' unclassified
-- titles list.

CREATE TABLE country_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,
    label       VARCHAR(150) NOT NULL,
    countries   TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_country_groups_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leads
    ADD COLUMN country_group_id INT UNSIGNED NULL AFTER company_country,
    ADD CONSTRAINT fk_leads_country_group FOREIGN KEY (country_group_id) REFERENCES country_groups(id) ON DELETE SET NULL,
    ADD KEY idx_leads_country_group (country_group_id);

-- Optional additional single-value criterion on ICP Segments, alongside
-- the existing multi-value company_country text list -- lets an ICP
-- target a whole region instead of (or in addition to) specific
-- countries, same single-select pattern as vertical_id/service_id/role_group_id.
ALTER TABLE icp_segments
    ADD COLUMN country_group_id INT UNSIGNED NULL AFTER company_country,
    ADD CONSTRAINT fk_icp_segments_country_group FOREIGN KEY (country_group_id) REFERENCES country_groups(id) ON DELETE SET NULL;

INSERT INTO country_groups (code, label, countries) VALUES
    ('AMERICAS', 'Americas',
     'United States, USA, US, Canada, Brazil, Mexico, Argentina, Chile, Colombia, Peru, Ecuador, Uruguay, Costa Rica, Panama'),
    ('UK_EUROPE', 'UK, Ireland & Europe',
     'United Kingdom, UK, England, Scotland, Wales, Northern Ireland, Ireland, Germany, France, Spain, Italy, Netherlands, Belgium, Switzerland, Sweden, Norway, Denmark, Finland, Poland, Austria, Portugal, Greece, Czech Republic, Romania, Hungary, Ukraine'),
    ('INDIA_ASIA', 'India & Asia',
     'India, Singapore, Japan, China, Hong Kong, South Korea, Malaysia, Indonesia, Philippines, Thailand, Vietnam, Taiwan, UAE, United Arab Emirates, Saudi Arabia, Israel, Pakistan, Bangladesh, Sri Lanka'),
    ('ANZ', 'Australia & Oceania',
     'Australia, New Zealand');
