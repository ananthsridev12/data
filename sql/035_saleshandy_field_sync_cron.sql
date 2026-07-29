-- Round-robin "keep already-pushed leads' Saleshandy custom fields
-- current" cron -- same shape as sql/030_round_robin_sync.sql (campaign-
-- level "last attempt" column, one campaign per run) plus a lead-level
-- "last synced" column so a campaign with more pushed leads than one
-- run's batch size still eventually covers all of them across
-- successive runs, not just the same first N forever. See
-- SaleshandyClient::syncFieldsForNextCampaign().

ALTER TABLE campaigns
    ADD COLUMN saleshandy_field_sync_last_attempt_at DATETIME NULL;

ALTER TABLE lead_campaign_assignments
    ADD COLUMN saleshandy_fields_synced_at DATETIME NULL;

-- Opt-in per company: the scheduled cron does nothing for a company
-- until an admin turns this on (see public/company_profile.php) --
-- unlike the other three Saleshandy crons, which have always run
-- unconditionally once configured. The manual "Run now" button on
-- icp_segments.php ignores this flag (an explicit click is its own
-- consent), same as every other "Run now" button in this app.
ALTER TABLE companies
    ADD COLUMN saleshandy_field_sync_cron_enabled TINYINT(1) NOT NULL DEFAULT 0;
