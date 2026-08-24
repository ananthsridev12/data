-- Lets a campaign also record which Country Group it's targeting (e.g.
-- "Americas", "UK, Ireland & Europe") -- mirrors vertical_id/service_id
-- (sql/018_campaign_vertical_service.sql), same optional single-select
-- pattern, reusing the country_groups table ICP Segments already targets
-- with (sql/027_country_groups.sql). Purely a categorization/reporting
-- field on the campaign itself -- it doesn't filter which leads get
-- assigned to the campaign (that's still governed by the ICP linked to
-- it, if any).

ALTER TABLE campaigns
    ADD COLUMN country_group_id INT UNSIGNED NULL AFTER service_id,
    ADD CONSTRAINT fk_campaigns_country_group FOREIGN KEY (country_group_id) REFERENCES country_groups(id) ON DELETE SET NULL,
    ADD KEY idx_campaigns_country_group (country_group_id);
