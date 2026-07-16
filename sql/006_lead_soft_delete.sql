-- Soft delete for leads: hides the lead everywhere by default without
-- touching its campaign-assignment/import history (a hard delete would
-- cascade-remove that history via the lead_campaign_assignments FK).

ALTER TABLE leads
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD CONSTRAINT fk_leads_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id),
    ADD KEY idx_leads_deleted_at (deleted_at);
