-- Per-step-send-event history, at the granularity lead_campaign_assignments
-- cannot represent (it only keeps one first-send date and one furthest-
-- step-reached per lead-per-campaign -- see SaleshandyClient::syncCampaign()).
-- Fed from the same raw, pre-aggregation rows fetchSequenceActivity()
-- already returns, via SaleshandyClient::persistSendEvents(), called from
-- both syncCampaign() (incremental) and backfillHistoricalDates() (full
-- 2-year catch-up) -- same idempotent-upsert-by-natural-key pattern those
-- methods already use elsewhere, so re-fetching a wide date range never
-- duplicates rows. Powers the Reports module's Daily Activity, Weekly, and
-- per-Step (raw, non-cumulative) breakdowns.

CREATE TABLE saleshandy_send_events (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id      INT UNSIGNED NOT NULL,
    lead_id          BIGINT UNSIGNED NULL,
    recipient_email  VARCHAR(190) NOT NULL,
    step_number      SMALLINT UNSIGNED NOT NULL,
    sent_date        DATE NOT NULL,
    sent_at          DATETIME NULL,
    open_count       INT UNSIGNED NOT NULL DEFAULT 0,
    last_opened_at   DATETIME NULL,
    replied          TINYINT(1) NOT NULL DEFAULT 0,
    bounced          TINYINT(1) NOT NULL DEFAULT 0,
    unsubscribed     TINYINT(1) NOT NULL DEFAULT 0,
    sentiment        VARCHAR(20) NULL,
    synced_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_send_event (campaign_id, recipient_email, step_number, sent_date),
    KEY idx_send_events_campaign_date (campaign_id, sent_date),
    KEY idx_send_events_date (sent_date),
    KEY idx_send_events_lead (lead_id),
    CONSTRAINT fk_send_events_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_send_events_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
