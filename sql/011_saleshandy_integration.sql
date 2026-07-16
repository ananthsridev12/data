-- Direct Saleshandy API integration: push assigned leads into a mapped
-- sequence step, and pull delivery/reply/bounce activity back. See
-- app/includes/SaleshandyClient.php.

ALTER TABLE campaigns
    ADD COLUMN saleshandy_step_id VARCHAR(100) NULL AFTER saleshandy_sequence_id,
    ADD COLUMN saleshandy_last_synced_at DATETIME NULL AFTER saleshandy_step_id;

-- Local tag list. Mirrors Saleshandy's own tags (synced in by name/id via
-- "Sync from Saleshandy" on tags.php) plus any created locally, which get
-- created on Saleshandy's side automatically the first time they're sent
-- with a push (Saleshandy's import endpoint creates a tag by name if it
-- doesn't already exist -- see SaleshandyClient::pushProspects()).
CREATE TABLE tags (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name               VARCHAR(100) NOT NULL,
    saleshandy_tag_id  VARCHAR(100) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_tags (
    lead_id  BIGINT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (lead_id, tag_id),
    CONSTRAINT fk_lead_tags_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which of our fields get sent to Saleshandy on push, and under what
-- Saleshandy field label (from SaleshandyClient::listFields()). Lets an
-- admin limit the push to a subset of fields instead of sending everything
-- we have. First Name/Last Name/Email are always sent regardless of this
-- table's contents (Saleshandy requires them), so they're never rows here.
CREATE TABLE saleshandy_field_mappings (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_field_key    VARCHAR(100) NOT NULL,
    saleshandy_label  VARCHAR(150) NOT NULL,
    enabled           TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_shfm_field (lead_field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
