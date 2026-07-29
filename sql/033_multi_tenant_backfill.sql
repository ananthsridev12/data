-- Multi-tenant retrofit, stage 1b: backfill existing production data onto
-- a single "Company 1", a single team, and a single designated data
-- owner, so 034's NOT NULL/FK/uniqueness tightening has something valid
-- to land on. Run once, after 032, before 034.
--
-- Every existing user becomes/stays an Admin of Company 1 -- there is no
-- signal in current data to infer a different role for any of them;
-- downgrade specific users to team_lead/member manually afterward via
-- users.php once that UI ships (stage 2). All of them are also placed
-- into the one team created below.

INSERT INTO companies (name, is_active, lead_cooldown_days) VALUES ('Company 1', 1, 90);
SET @company1_id = LAST_INSERT_ID();

INSERT INTO teams (company_id, name) VALUES (@company1_id, 'Team 1');
SET @team1_id = LAST_INSERT_ID();

UPDATE users SET company_id = @company1_id, team_id = @team1_id, role = 'admin' WHERE company_id IS NULL;

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

-- Single designated data owner: the seeded admin account (see
-- sql/002_seed_admin_user.sql). If production's admin login uses a
-- different email than the seed default (e.g. changed after initial
-- setup, per that file's own "change this password" instruction),
-- update this email BEFORE running the migration so @owner_id resolves
-- to the right account.
SET @owner_id = (SELECT id FROM users WHERE email = 'admin@example.com' LIMIT 1);

-- leads.owner_id: every existing lead is re-owned to the single
-- designated member rather than preserving each lead's original
-- importer -- a deliberate consolidation, not an attempt to reconstruct
-- per-actor history. Leaves owner_id NULL only if @owner_id itself
-- couldn't be resolved above (fix the email and re-run rather than
-- leaving this unset).
UPDATE leads SET owner_id = @owner_id WHERE owner_id IS NULL AND @owner_id IS NOT NULL;

-- campaigns.saleshandy_account_owner_id: every existing campaign is
-- likewise re-owned to that same single member, not to whoever
-- originally created it -- see 032's comment on why this is a separate
-- column from created_by rather than reusing it.
UPDATE campaigns SET saleshandy_account_owner_id = @owner_id WHERE saleshandy_account_owner_id IS NULL AND @owner_id IS NOT NULL;

-- Saleshandy key handoff: with ownership fully consolidated onto
-- @owner_id above, only that one account needs the existing global API
-- key from app/config/config.php copied onto users.saleshandy_api_key
-- (encrypted) -- no other user's row needs it, since no campaign points
-- anywhere else. Run the encryption + UPDATE via a one-off PHP script
-- (added alongside the stage 3 work), not raw SQL, since the key must
-- be encrypted with the app's own secret before being stored -- this
-- migration file only documents the step, it doesn't perform it.
