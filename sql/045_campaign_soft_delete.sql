-- Soft delete for campaigns: hides a campaign everywhere by default
-- without touching its lead-assignment/history data -- a hard delete
-- would cascade-remove that history via several FKs (lead_campaign_
-- assignments, campaign_step_notes, saleshandy_send_events,
-- follow_up_tasks, icp_campaign_links all reference campaigns(id) ON
-- DELETE CASCADE). Same pattern as leads (sql/006_lead_soft_delete.sql).

ALTER TABLE campaigns
    ADD COLUMN deleted_at DATETIME NULL AFTER is_active,
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD CONSTRAINT fk_campaigns_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id),
    ADD KEY idx_campaigns_deleted_at (deleted_at);
