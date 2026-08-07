-- Follow-up task module: auto-creates a task to manually follow up (e.g.
-- send a LinkedIn connection request) once a lead's engagement on a
-- campaign crosses that campaign's own thresholds -- opens, clicks, and/or
-- a positive reply. See app/includes/FollowUpTaskRepository.php.

-- Per-campaign thresholds (NULL/0 = that signal doesn't trigger a task for
-- this campaign). Deliberately per-campaign, not company-wide, since a
-- high-intent campaign may want a lower bar than a cold-outreach one.
ALTER TABLE campaigns
    ADD COLUMN followup_open_threshold INT UNSIGNED NULL AFTER saleshandy_field_sync_last_attempt_at,
    ADD COLUMN followup_click_threshold INT UNSIGNED NULL AFTER followup_open_threshold,
    ADD COLUMN followup_on_positive_reply TINYINT(1) NOT NULL DEFAULT 0 AFTER followup_click_threshold;

CREATE TABLE follow_up_tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    campaign_id INT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    -- Snapshot of the campaign's owner at creation time -- not re-resolved
    -- live, so a later change of campaign owner doesn't retroactively move
    -- an already-created task to someone new (matches how
    -- saleshandy_account_owner_id is "effectively locked" elsewhere).
    assigned_to INT UNSIGNED NULL,
    -- Which signal(s) contributed to this task existing -- a lead can
    -- trigger more than one (e.g. opened AND clicked), and a later sync
    -- can add a flag that wasn't true yet at creation time.
    flag_opens TINYINT(1) NOT NULL DEFAULT 0,
    flag_clicks TINYINT(1) NOT NULL DEFAULT 0,
    flag_reply TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'in_progress', 'done', 'skipped') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    -- Set (and re-set) whenever a new signal fires on a lead that already
    -- has an open task -- surfaced as a "re-engaged" badge and used to
    -- bump the task back to the top of the list, instead of creating a
    -- second task for the same lead+campaign.
    reengaged_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_id) REFERENCES lead_campaign_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_company_status (company_id, status),
    KEY idx_assigned_to_status (assigned_to, status),
    KEY idx_lead_campaign (lead_id, campaign_id)
);
