-- Supports switching both scheduled jobs from "loop every campaign/ICP
-- in one request" to "process just the next one due, per invocation" --
-- a single request touching 15+ campaigns (each up to two Saleshandy API
-- calls, each with a 30s timeout) can take minutes and risks hitting
-- PHP's max_execution_time or the web server's own request timeout on
-- shared hosting. Round-robin selection needs its OWN "last attempted"
-- timestamp, separate from the existing "last successful sync" columns
-- (campaigns.saleshandy_last_synced_at, which also drives the
-- incremental fetch window and must only advance on real success) --
-- otherwise a persistently-failing campaign/ICP would never advance its
-- timestamp and would get retried on every single tick forever,
-- starving every other campaign/ICP from ever being picked.

ALTER TABLE campaigns
    ADD COLUMN saleshandy_last_sync_attempt_at DATETIME NULL AFTER saleshandy_last_synced_at;

ALTER TABLE icp_segments
    ADD COLUMN last_distribution_attempt_at DATETIME NULL AFTER auto_push_enabled;
