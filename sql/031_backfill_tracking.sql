-- Lets "Backfill dates & status" (previously a manual, one-off,
-- per-campaign button) run automatically via a third round-robin cron,
-- same pattern as sql/030_round_robin_sync.sql.
--
-- saleshandy_backfilled_at: set once a campaign has been successfully
-- backfilled at all (whether via the existing manual button or the new
-- cron) -- once set, that campaign is excluded from future automated
-- backfill candidates, since a full 2-year re-fetch is expensive
-- (multiple paginated API calls) and pointless to repeat once done; the
-- regular Saleshandy Sync cron already keeps things current going
-- forward from there.
--
-- saleshandy_backfill_last_attempt_at: a separate "last ATTEMPT" column
-- (same reasoning as sql/030) so a campaign that keeps failing to
-- backfill still advances its attempt timestamp and doesn't get
-- retried on every single tick forever, starving every other
-- not-yet-backfilled campaign from ever getting a turn.

ALTER TABLE campaigns
    ADD COLUMN saleshandy_backfilled_at DATETIME NULL AFTER saleshandy_last_sync_attempt_at,
    ADD COLUMN saleshandy_backfill_last_attempt_at DATETIME NULL AFTER saleshandy_backfilled_at;
