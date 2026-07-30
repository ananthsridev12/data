-- Day-by-day send forecast (public/capacity_planner.php's "Day-by-day
-- forecast" section) needs each automated email step's individual day
-- offset, not just the aggregate step_count/max cadence 036 already
-- caches -- e.g. a D1/D3/D7 sequence needs [1,3,7], not just "3 steps,
-- 7-day cadence", to know exactly which future date each remaining
-- touch for an in-flight lead lands on.
--
-- Stored as JSON [{"number":1,"days":0},{"number":3,"days":2},...] --
-- step NUMBER is kept alongside its day-offset (not just a plain
-- offset array) because lead_campaign_assignments.saleshandy_current_step
-- refers to Saleshandy's own step numbering, which can have gaps once
-- non-email steps (LinkedIn/call/WhatsApp/custom) are filtered out --
-- see CapacityPlanner::refreshCampaign(). TEXT rather than a native
-- JSON column, matching this app's existing convention for
-- semi-structured data (see import_row_errors.raw_row_json).
ALTER TABLE campaigns
    ADD COLUMN saleshandy_step_schedule_json TEXT NULL AFTER saleshandy_cadence_days;
