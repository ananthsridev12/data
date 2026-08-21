-- Opt-in per-ICP toggle: when on, a previously-assigned lead only
-- re-qualifies for this ICP's matching once its latest assignment's
-- sequence actually finished (saleshandy_current_step >= that
-- campaign's saleshandy_step_count -- the pre-existing CapacityPlanner
-- column, sql/036_capacity_planner.sql, also kept fresh by regular
-- syncs and Campaign Flow visits) with delivery_status still 'Active'
-- (no reply), IN ADDITION TO the
-- existing resolved + lead_cooldown_days rule -- not instead of it. Off
-- by default so existing ICPs keep today's broader cooldown-only
-- reassignment behavior unchanged; an admin opts each ICP in
-- individually where "only re-target leads who finished with silence"
-- is actually what they want. See LeadRepository::buildWhere()'s
-- 'require_sequence_completed_if_reassigning' filter and
-- IcpRepository::toFilters().
ALTER TABLE icp_segments
    ADD COLUMN require_sequence_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_push_enabled;
