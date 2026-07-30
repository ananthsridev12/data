-- Capacity Planner: "given the campaigns/steps/email accounts we have,
-- how much sending capacity do we have and how long will our current
-- backlog take?" (public/capacity_planner.php, app/includes/CapacityPlanner.php).
--
-- Saleshandy's own API exposes no per-account daily-send-limit field
-- (checked against both the open-api MCP tool responses and the
-- official CLI's source, @saleshandy/saleshandy-cli on npm -- neither
-- surfaces one), so that number is a per-company assumption the user
-- sets themselves rather than something we can pull automatically.
-- Everything else (which accounts are connected/active, how many steps
-- a sequence has, its cadence) IS pullable, and is cached here rather
-- than fetched live on every page view -- refreshed via an explicit
-- "Refresh from Saleshandy" button on the planner page.

ALTER TABLE companies
    ADD COLUMN assumed_daily_send_limit INT UNSIGNED NOT NULL DEFAULT 30;

-- Cached per connected member -- how many of their Saleshandy email
-- accounts are usable (status = Active) right now. Refreshed alongside
-- assumed_daily_send_limit to compute this member's total daily
-- sending capacity (active_accounts x assumed_daily_send_limit).
ALTER TABLE users
    ADD COLUMN saleshandy_active_email_accounts INT UNSIGNED NULL AFTER saleshandy_connected_at,
    ADD COLUMN saleshandy_capacity_synced_at DATETIME NULL AFTER saleshandy_active_email_accounts;

-- Cached per campaign -- how many steps its linked sequence has, and
-- the cadence (days from the first step to the last, i.e. how long one
-- lead's full run through the sequence takes) -- both needed to turn
-- raw send capacity into "how many new leads/day can we safely start."
ALTER TABLE campaigns
    ADD COLUMN saleshandy_step_count INT UNSIGNED NULL AFTER saleshandy_step_id,
    ADD COLUMN saleshandy_cadence_days INT UNSIGNED NULL AFTER saleshandy_step_count,
    ADD COLUMN saleshandy_capacity_synced_at DATETIME NULL AFTER saleshandy_cadence_days;
