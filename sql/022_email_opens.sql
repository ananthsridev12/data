-- Email open tracking, captured from the same Saleshandy activity fetch
-- syncCampaign()/backfillHistoricalDates() already do (Open Count / Last
-- Opened At fields were previously parsed out and discarded). Cumulative
-- snapshot as of the last fetch, not a per-day open log -- see
-- SaleshandyClient::fetchSequenceActivity()'s docblock.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN open_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER saleshandy_synced_at,
    ADD COLUMN last_opened_at DATETIME NULL AFTER open_count;
