-- Traces a lead_campaign_assignments row back to the ICP segment whose
-- distribution cron run actually created it (NULL for assignments made
-- the normal manual way, via campaign_select_leads.php /
-- lead_bulk_campaign_add.php). Needed so an ICP's Saleshandy performance
-- report (icp_report.php) can count only the leads this ICP itself put
-- into a campaign -- a campaign can be linked to more than one ICP, so
-- "every lead in this ICP's linked campaigns" would double-count or
-- misattribute leads added manually or by a sibling ICP sharing that
-- same campaign.
--
-- Existing rows are left NULL (unknown attribution) -- there's no way to
-- retroactively determine which of them came from an ICP cron run vs a
-- manual add, so the report only reflects assignments made after this
-- migration is applied.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN icp_id INT UNSIGNED NULL AFTER assigned_by,
    ADD CONSTRAINT fk_assignments_icp FOREIGN KEY (icp_id) REFERENCES icp_segments(id) ON DELETE SET NULL,
    ADD KEY idx_assignments_icp (icp_id);
