-- Bucketed employee-count band (e.g. "51-200"), computed from the raw
-- leads.employee_count value at import time and via a one-time backfill
-- for existing leads -- see app/includes/EmployeeCountRangeClassifier.php.
-- employee_count itself is left untouched (still holds the exact number
-- as imported); this is purely an additional, coarser field for filtering/
-- ICP criteria, where the raw exact-number column was too granular to be
-- a useful checkbox filter.

ALTER TABLE leads
    ADD COLUMN employee_count_range VARCHAR(20) NULL AFTER employee_count;
