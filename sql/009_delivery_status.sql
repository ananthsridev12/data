-- Per-lead-per-campaign Saleshandy delivery/sequence status (Active,
-- Waiting, Paused, Replied, Bounced, All Bounced, Hard Bounced,
-- Soft Bounced, Block Bounced) -- see DELIVERY_STATUSES in
-- app/config/constants.php. Distinct from the existing `status` ENUM,
-- which tracks this app's own assignment workflow (assigned/exported/
-- pushed), not Saleshandy's reported delivery state.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN delivery_status VARCHAR(50) NULL AFTER status,
    ADD KEY idx_assignments_delivery_status (delivery_status);
