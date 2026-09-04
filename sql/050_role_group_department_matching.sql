-- Opt-in per-role-group toggles: when on, RoleGroupClassifier::classify()
-- also checks a lead's departments/sub_departments value (not just
-- title) against that group's SAME keyword list. Off by default, so
-- existing role groups keep today's title-only matching unchanged; an
-- admin opts each group in individually via role_groups.php. See
-- RoleGroupClassifier.php and public/lead_reclassify_roles.php (which
-- needs to be re-run to apply a newly-toggled flag to existing leads,
-- same as any keyword edit today).
ALTER TABLE role_groups
    ADD COLUMN match_departments TINYINT(1) NOT NULL DEFAULT 0 AFTER keywords,
    ADD COLUMN match_sub_departments TINYINT(1) NOT NULL DEFAULT 0 AFTER match_departments;
