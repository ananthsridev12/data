-- Caches Saleshandy's email verification result per lead so "Push to
-- Saleshandy" doesn't re-verify (and re-spend credits on) the same email
-- every time -- checked once, cached, only re-checked if explicitly
-- cleared. See SaleshandyClient::checkVerificationStatus() /
-- classifyVerificationTier().

ALTER TABLE leads
    ADD COLUMN email_verification_status VARCHAR(50) NULL,
    ADD COLUMN email_verification_checked_at DATETIME NULL;
