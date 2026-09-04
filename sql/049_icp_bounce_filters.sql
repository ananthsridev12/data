-- Optional ICP match criteria based on a lead's CURRENT (latest)
-- campaign assignment bounce/delivery state -- mirrors the same
-- LeadRepository::buildWhere() 'bounce_status'/'bounce_type'/
-- 'delivery_status' filters just added to campaign_select_leads.php and
-- dashboard.php, now usable as ICP matching criteria too (see
-- IcpRepository::toFilters()). All optional/blank by default, so
-- existing ICPs are unaffected:
--   bounce_status_filter: 'pending'|'delivered'|'bounced'|'none' (never
--     assigned) -- Wave-1's own tracking, single value.
--   bounce_type_filter: one of WaveAssigner::BOUNCE_TYPES, or 'none' --
--     single value, only meaningful once a bounce is actually recorded.
--   delivery_status_filter: comma-separated list of raw Saleshandy
--     delivery_status values (e.g. "Active, Replied"), same storage
--     convention as icp_segments.company_country/industry/seniority/
--     employee_count (parsed via RoleGroupClassifier::parseKeywords()).
ALTER TABLE icp_segments
    ADD COLUMN bounce_status_filter VARCHAR(20) NULL AFTER employee_count,
    ADD COLUMN bounce_type_filter VARCHAR(50) NULL AFTER bounce_status_filter,
    ADD COLUMN delivery_status_filter VARCHAR(500) NULL AFTER bounce_type_filter;
