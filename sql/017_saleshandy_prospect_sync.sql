-- Caches Saleshandy's internal prospect id per lead (found by email search
-- the first time it's needed) so re-syncing field updates to an
-- already-pushed lead's Saleshandy contact doesn't need a fresh lookup
-- every time. See SaleshandyClient::findProspectId() / ::syncFieldsToSaleshandy().

ALTER TABLE leads
    ADD COLUMN saleshandy_prospect_id VARCHAR(100) NULL;
