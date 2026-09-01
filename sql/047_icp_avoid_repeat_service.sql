-- Opt-in per-ICP toggle: when on, a lead with prior assignment history is
-- excluded from a target campaign whose Service matches the Service of any
-- campaign that lead has already been assigned to (any prior campaign, not
-- just their latest) -- regardless of cooldown/resolved status, and
-- regardless of require_sequence_completed. Guards against the case an ICP
-- links several campaigns pitching the SAME service as different sequence
-- variants (e.g. "C1-DM-DT-CPQ..." vs "C2-DM-DT-CPQ..."): without this, a
-- completed/reassigned lead's random bucket placement (see
-- IcpRepository::splitLeadIds()) could land them back in another
-- same-service campaign -- a genuine repeat pitch, not a graduation to a
-- new offer. A brand-new lead with no prior assignment at all is never
-- affected. Off by default so existing ICPs keep today's behavior
-- unchanged; a campaign with no service_id set can never trigger or be
-- blocked by this check. See WaveAssigner::filterEligibleForCampaign().
ALTER TABLE icp_segments
    ADD COLUMN avoid_repeat_service TINYINT(1) NOT NULL DEFAULT 0 AFTER require_sequence_completed;
