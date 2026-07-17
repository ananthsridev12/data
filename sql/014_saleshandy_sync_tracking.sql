-- Two things caught from real usage of the Saleshandy integration:
-- 1. There was no way to see which step of a sequence a lead has reached
--    (Saleshandy's own analytics data includes a "Step Number" per
--    activity row -- see SaleshandyClient::fetchSequenceActivity()).
-- 2. `exported_at` reflects when Saleshandy actually sent the first
--    email (which can be genuinely old, e.g. a sequence that's been
--    running for months) -- that got confused with "when did our tool
--    push/pull/sync this row", which needs its own timestamp.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN saleshandy_current_step INT UNSIGNED NULL AFTER delivery_status,
    ADD COLUMN saleshandy_synced_at DATETIME NULL AFTER saleshandy_current_step;
