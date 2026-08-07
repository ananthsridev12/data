-- Tracks link clicks (Saleshandy's "Click Count" per prospect, from the
-- same /analytics/consolidated-stats response open_count already comes
-- from -- see SaleshandyClient::fetchSequenceActivity()) so leads who
-- clicked a link in the email can be found and manually followed up
-- with a LinkedIn connection request (see the "Clicked" filter on
-- public/campaign_leads.php). Cumulative count only, same as
-- open_count -- Saleshandy doesn't expose a "last clicked at" the way
-- it does for opens.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN click_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_opened_at;

ALTER TABLE saleshandy_send_events
    ADD COLUMN click_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_opened_at;
