-- Tracks when a lead was first pushed to Saleshandy, distinct from
-- saleshandy_synced_at (which gets overwritten by every later sync/push)
-- -- "at what time we first pushed this data to Saleshandy" was previously
-- unanswerable once a second sync had run. See campaign_saleshandy_push.php
-- (sets it once, COALESCE-guarded) and SaleshandyClient::pullNewProspects()
-- (best-effort: earliest known send time for a prospect enrolled directly
-- in Saleshandy, never actually pushed from here).

ALTER TABLE lead_campaign_assignments
    ADD COLUMN saleshandy_pushed_at DATETIME NULL AFTER saleshandy_synced_at;
