-- Multi-tenant retrofit, stage 1a: new companies/teams tables, the new
-- user/lead/campaign columns those require, and a nullable company_id
-- added to every currently-global admin-configured/data table.
--
-- This migration alone changes NO runtime behavior -- every new
-- company_id column is nullable and unindexed-by-uniqueness, every new
-- FK is optional (ON DELETE SET NULL), and no application code reads
-- these columns yet. See 033_multi_tenant_backfill.sql for populating
-- them against existing data, and 034_multi_tenant_tighten.sql for
-- making company_id NOT NULL + FK'd + part of each table's uniqueness
-- key once backfilled. Run all three in order.

CREATE TABLE companies (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    lead_cooldown_days  INT UNSIGNED NOT NULL DEFAULT 90,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teams (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teams_company_name (company_id, name),
    CONSTRAINT fk_teams_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users: company membership, team membership, 3-way role, and the
-- per-member Saleshandy credential (encrypted at rest by the app --
-- see app/includes/SaleshandyClient.php). role widens directly (existing
-- 'admin'/'member' values remain valid under the new enum, so this is
-- safe without a nullable staging step). company_id stays nullable here
-- and is tightened to NOT NULL in 034, once every existing user has been
-- backfilled onto Company 1 (033).
ALTER TABLE users
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN team_id INT UNSIGNED NULL AFTER role,
    ADD COLUMN is_team_lead TINYINT(1) NOT NULL DEFAULT 0 AFTER team_id,
    MODIFY COLUMN role ENUM('admin', 'team_lead', 'member') NOT NULL DEFAULT 'member',
    ADD COLUMN saleshandy_api_key VARBINARY(512) NULL AFTER invite_expires_at,
    ADD COLUMN saleshandy_connected_at DATETIME NULL AFTER saleshandy_api_key,
    ADD CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    ADD KEY idx_users_company (company_id),
    ADD KEY idx_users_team (team_id);

-- leads: ownership. Stays nullable permanently (ON DELETE SET NULL) --
-- unlike company_id elsewhere, this column is never tightened to NOT
-- NULL, since "owner unknown" is a legitimate long-term state for
-- pre-existing rows backfilled from incomplete import history (033).
ALTER TABLE leads
    ADD COLUMN owner_id INT UNSIGNED NULL AFTER last_import_batch_id,
    ADD CONSTRAINT fk_leads_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_leads_owner (owner_id);

-- campaigns: which member's Saleshandy account this campaign's
-- sequence/push/sync/backfill calls run through. Distinct from
-- created_by (who set the campaign up) -- defaults to it at insert,
-- but is reassignable up until saleshandy_sequence_id is first set, at
-- which point changing it would orphan the campaign's remote sequence.
ALTER TABLE campaigns
    ADD COLUMN saleshandy_account_owner_id INT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_campaigns_saleshandy_owner FOREIGN KEY (saleshandy_account_owner_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_campaigns_saleshandy_owner (saleshandy_account_owner_id);

-- Nullable company_id on every currently-global admin-configured/data
-- table. Same staging as users.company_id above: nullable now,
-- backfilled in 033, tightened (NOT NULL + FK + company-scoped
-- uniqueness) in 034.
ALTER TABLE leads
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_leads_company_id (company_id);

ALTER TABLE campaigns
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_campaigns_company (company_id);

ALTER TABLE import_batches
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_import_batches_company (company_id);

ALTER TABLE import_field_mappings
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_import_field_mappings_company (company_id);

ALTER TABLE verticals
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_verticals_company (company_id);

ALTER TABLE services
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_services_company (company_id);

ALTER TABLE role_groups
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_role_groups_company (company_id);

ALTER TABLE country_groups
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_country_groups_company (company_id);

ALTER TABLE tags
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_tags_company (company_id);

ALTER TABLE custom_fields
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_custom_fields_company (company_id);

-- icp_segments.company_id must always match the company_id of every
-- campaign it links via icp_campaign_links -- that cross-table
-- invariant can't be expressed as a plain FK here (same reasoning as
-- the existing "percentages sum to 100" rule) and is enforced in
-- IcpRepository application code instead.
ALTER TABLE icp_segments
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_icp_segments_company (company_id);

ALTER TABLE saleshandy_field_mappings
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_shfm_company (company_id);

-- suppressed_domains: was account-wide-and-global; per decision 1
-- becomes per-company, so two companies can independently suppress (or
-- not suppress) the same domain.
ALTER TABLE suppressed_domains
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_suppressed_domains_company (company_id);

-- campaign_step_notes: reachable only through campaign_id, so scope is
-- already implied transitively by its parent campaign's company_id --
-- but it gets its own column anyway (defense in depth per the plan's
-- "no way to forget the filter" goal: any future query against this
-- table that forgets to join campaigns still can't cross a tenant
-- boundary).
ALTER TABLE campaign_step_notes
    ADD COLUMN company_id INT UNSIGNED NULL AFTER id,
    ADD KEY idx_campaign_step_notes_company (company_id);

-- bounce_type_suppression_settings has no surrogate id -- its PK today
-- is bounce_type alone. Stays that way structurally; company_id becomes
-- part of a new composite PK in 034 (dropping the current single-column
-- PK first), once every existing row has been duplicated per company in
-- 033.
ALTER TABLE bounce_type_suppression_settings
    ADD COLUMN company_id INT UNSIGNED NULL FIRST;
