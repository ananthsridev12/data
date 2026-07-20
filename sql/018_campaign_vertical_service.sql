-- Each campaign pitches one particular service within one vertical (e.g.
-- "DM-DT-ESI-US-01" = Digital Transformation / ESI) -- lets the campaign
-- summary report show Vertical/Service Pitched per campaign without
-- inferring it from whatever mix of per-lead vertical/service values ended
-- up assigned to it.

ALTER TABLE campaigns
    ADD COLUMN vertical_id INT UNSIGNED NULL AFTER description,
    ADD COLUMN service_id INT UNSIGNED NULL AFTER vertical_id,
    ADD CONSTRAINT fk_campaigns_vertical FOREIGN KEY (vertical_id) REFERENCES verticals(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_campaigns_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    ADD KEY idx_campaigns_vertical (vertical_id),
    ADD KEY idx_campaigns_service (service_id);
