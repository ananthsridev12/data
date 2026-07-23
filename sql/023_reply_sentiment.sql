-- Categorizes a reply's outcome using Saleshandy's own "Current Sentiment"
-- field (Positive / Negative / Neutral / Uncategorized), returned by the
-- same /analytics/consolidated-stats fetch this app already makes for
-- every sync -- see SaleshandyClient::fetchSequenceActivity(). Only
-- meaningful once delivery_status = 'Replied'; null otherwise.

ALTER TABLE lead_campaign_assignments
    ADD COLUMN reply_sentiment VARCHAR(20) NULL AFTER last_opened_at;
