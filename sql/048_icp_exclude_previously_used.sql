-- Opt-in per-ICP toggle: when on, this ICP only ever matches a lead that
-- has NEVER been assigned to ANY campaign at all -- no cooldown-based
-- reassignment candidates, regardless of how long ago or how cleanly
-- their prior campaign resolved. The ICP-level counterpart to the "Hide
-- leads already used in ANY campaign" checkbox added to
-- campaign_select_leads.php (the manual Add Leads screen) -- same
-- underlying rule (LeadRepository::buildWhere()'s
-- 'assigned_campaign_id' = 'none'), now reachable from automated ICP
-- distribution too. Off by default, so existing ICPs keep today's
-- broader cooldown-based reassignment behavior unchanged; unlike
-- avoid_repeat_service/require_sequence_completed (which only narrow
-- WHICH previously-assigned leads re-qualify), this excludes
-- reassignment entirely for the ICP it's turned on for. See
-- IcpRepository::toFilters().
ALTER TABLE icp_segments
    ADD COLUMN exclude_previously_used TINYINT(1) NOT NULL DEFAULT 0 AFTER avoid_repeat_service;
