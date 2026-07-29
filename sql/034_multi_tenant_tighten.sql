-- Multi-tenant retrofit, stage 1c: tighten every company_id column added
-- in 032 and backfilled in 033 to NOT NULL, add its FK to companies, and
-- convert each table's global-uniqueness key to a company-scoped one.
-- Run once, after 032 and 033 have both completed successfully and every
-- company_id column is confirmed to have zero NULLs (verify with
-- `SELECT COUNT(*) FROM <table> WHERE company_id IS NULL` per table
-- before running this file).

ALTER TABLE users
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE leads
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_leads_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_leads_email,
    ADD UNIQUE KEY uq_leads_company_email (company_id, email);

ALTER TABLE campaigns
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_campaigns_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_campaigns_name,
    ADD UNIQUE KEY uq_campaigns_company_name (company_id, name);

ALTER TABLE import_batches
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_import_batches_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

ALTER TABLE import_field_mappings
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_import_field_mappings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_mapping_name,
    ADD UNIQUE KEY uq_mapping_company_name (company_id, name);

ALTER TABLE verticals
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_verticals_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_verticals_code,
    ADD UNIQUE KEY uq_verticals_company_code (company_id, code);

ALTER TABLE services
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_services_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_services_code,
    ADD UNIQUE KEY uq_services_company_code (company_id, code);

ALTER TABLE role_groups
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_role_groups_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_role_groups_code,
    ADD UNIQUE KEY uq_role_groups_company_code (company_id, code);

ALTER TABLE country_groups
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_country_groups_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_country_groups_code,
    ADD UNIQUE KEY uq_country_groups_company_code (company_id, code);

ALTER TABLE tags
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_tags_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_tags_name,
    ADD UNIQUE KEY uq_tags_company_name (company_id, name);

ALTER TABLE custom_fields
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_custom_fields_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_custom_fields_key,
    ADD UNIQUE KEY uq_custom_fields_company_key (company_id, field_key);

ALTER TABLE icp_segments
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_icp_segments_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_icp_segments_name,
    ADD UNIQUE KEY uq_icp_segments_company_name (company_id, name);

ALTER TABLE saleshandy_field_mappings
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_shfm_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_shfm_field,
    ADD UNIQUE KEY uq_shfm_company_field (company_id, lead_field_key);

ALTER TABLE suppressed_domains
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_suppressed_domains_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    DROP KEY uq_suppressed_domain,
    ADD UNIQUE KEY uq_suppressed_domains_company_domain (company_id, domain);

-- campaign_step_notes: (campaign_id, step_number) already disambiguates
-- correctly since one campaign belongs to exactly one company -- no
-- uniqueness-key change needed, just the NOT NULL + FK.
ALTER TABLE campaign_step_notes
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_campaign_step_notes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

-- bounce_type_suppression_settings had no surrogate id -- its PK was
-- bounce_type alone (one global row per type). Replace with a
-- composite PK so each company can independently configure the same
-- bounce_type.
ALTER TABLE bounce_type_suppression_settings
    MODIFY COLUMN company_id INT UNSIGNED NOT NULL,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (company_id, bounce_type),
    ADD CONSTRAINT fk_bounce_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
