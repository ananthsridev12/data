-- Multi-tenant retrofit, stage 1b: backfill existing production data onto
-- a single "Company 1" so 034's NOT NULL/FK/uniqueness tightening has
-- something valid to land on. Run once, after 032, before 034.
--
-- Every existing user becomes an Admin of Company 1 -- there is no
-- signal in current data to infer a different role for any of them;
-- downgrade specific users to team_lead/member manually afterward via
-- users.php once that UI ships (stage 2).

INSERT INTO companies (name, is_active, lead_cooldown_days) VALUES ('Company 1', 1, 90);
SET @company1_id = LAST_INSERT_ID();

UPDATE users SET company_id = @company1_id, role = 'admin' WHERE company_id IS NULL;

UPDATE leads SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE campaigns SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE import_batches SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE import_field_mappings SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE verticals SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE services SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE role_groups SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE country_groups SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE tags SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE custom_fields SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE icp_segments SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE saleshandy_field_mappings SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE suppressed_domains SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE campaign_step_notes SET company_id = @company1_id WHERE company_id IS NULL;
UPDATE bounce_type_suppression_settings SET company_id = @company1_id WHERE company_id IS NULL;

-- leads.owner_id: attribute each lead to whoever uploaded the import
-- batch it last came in on. Leads with no import_batch (or a batch
-- whose uploader row no longer exists) stay NULL -- "owner unknown" is
-- a permanent, legitimate state for this column (see 032's comment).
UPDATE leads l
JOIN import_batches ib ON ib.id = l.last_import_batch_id
SET l.owner_id = ib.uploaded_by
WHERE l.owner_id IS NULL;

-- campaigns.saleshandy_account_owner_id: default every existing
-- campaign to whichever member created it -- see 032's comment on why
-- this is a separate column from created_by rather than reusing it.
UPDATE campaigns SET saleshandy_account_owner_id = created_by WHERE saleshandy_account_owner_id IS NULL;

-- Transitional Saleshandy key handoff: the one API key this app has
-- ever used lives in app/config/config.php today, shared by the whole
-- account. Copy it (encrypted) onto every existing user who owns at
-- least one campaign with a live saleshandy_sequence_id, so nothing
-- stops syncing the moment stage 3 code starts calling
-- SaleshandyClient::forUser() instead of ::fromConfig(). This is
-- intentionally a stopgap -- those users are flagged in the UI (stage
-- 3) as "using a shared/inherited key" until each connects their own
-- individual Saleshandy account. Run the encryption + UPDATE via a
-- one-off PHP script (php/scripts/backfill_saleshandy_keys.php, added
-- alongside the stage 3 work), not raw SQL, since the key must be
-- encrypted with the app's own secret before being stored -- this
-- migration file only documents the step, it doesn't perform it.
