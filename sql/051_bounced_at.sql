-- Saleshandy's /analytics/consolidated-stats API returns a "Bounced At"
-- timestamp per bounce event -- confirmed against real live account data
-- -- but nothing in this app captured it; the only timestamp kept was
-- saleshandy_synced_at (when OUR sync ran, not when the bounce actually
-- happened at Saleshandy). Adds a column to record it: set from the real
-- API timestamp when SaleshandyClient's sync detects a bounce
-- (SaleshandyClient::fetchSequenceActivity()/aggregateActivityByEmail()),
-- or NOW() as the best available fallback for the manual bounce-recording
-- paths (WaveAssigner::suppress()/suppressByEmail(), used by Bounce
-- Import, the campaign paste-bounces form, and the per-leader "Bounced"
-- button) which have no per-row API timestamp to draw on.
ALTER TABLE lead_campaign_assignments
    ADD COLUMN bounced_at DATETIME NULL AFTER bounce_type;
