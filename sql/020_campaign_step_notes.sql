-- Admin-authored "what this step is for" labels (e.g. "T1 -- pain point",
-- "T2 -- tool intro") overlaid on top of a campaign's real Saleshandy
-- sequence steps -- Saleshandy has no such field itself, this is purely
-- our own annotation layer. See public/campaign_flow.php.

CREATE TABLE campaign_step_notes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id  INT UNSIGNED NOT NULL,
    step_number  INT UNSIGNED NOT NULL,
    purpose      VARCHAR(255) NULL,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_campaign_step (campaign_id, step_number),
    CONSTRAINT fk_campaign_step_notes_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
