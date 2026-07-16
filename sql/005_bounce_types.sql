-- Records *why* a domain was suppressed (hard bounce, soft bounce, spam
-- complaint, invalid address, or other/unspecified), captured wherever a
-- bounce is recorded: the campaign's paste-a-bounce-list box, and the
-- bounce-report CSV import.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN bounce_type VARCHAR(50) NULL AFTER bounce_status;

ALTER TABLE suppressed_domains
    ADD COLUMN bounce_type VARCHAR(50) NULL AFTER reason;
