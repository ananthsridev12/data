<?php

/**
 * Buckets a raw employee-count value (usually a plain exact number as
 * imported, e.g. "245") into a standard firmographic band, for
 * leads.employee_count_range (sql/026_employee_count_range.sql). Fixed,
 * hardcoded bands -- unlike Role Groups, there's no admin-editable
 * ruleset here, since these boundaries aren't expected to change.
 */
class EmployeeCountRangeClassifier
{
    /** @var array<int,array{0:int,1:int,2:string}> [min, max, label], checked in order */
    public const BANDS = [
        [1, 10, '1-10'],
        [11, 50, '11-50'],
        [51, 200, '51-200'],
        [201, 500, '201-500'],
        [501, 1000, '501-1,000'],
        [1001, 5000, '1,001-5,000'],
        [5001, 10000, '5,001-10,000'],
        [10001, PHP_INT_MAX, '10,001+'],
    ];

    /**
     * All band labels in ascending size order -- for filter checkbox
     * lists, where the natural DB-distinct-values ordering would sort
     * these alphabetically (e.g. "1,001-5,000" before "11-50") instead of
     * by actual size.
     *
     * @return array<int,string>
     */
    public static function allLabels(): array
    {
        return array_column(self::BANDS, 2);
    }

    public static function classify(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Already looks like a range or an open-ended band (e.g.
        // "51-200", "10,001+") rather than a single exact number --
        // pass through unchanged rather than trying to re-parse
        // arbitrary already-bucketed text.
        if (str_contains($raw, '-') || str_contains($raw, '+')) {
            return $raw;
        }

        $digits = preg_replace('/[^\d]/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }
        $n = (int) $digits;

        foreach (self::BANDS as [$min, $max, $label]) {
            if ($n >= $min && $n <= $max) {
                return $label;
            }
        }
        return null;
    }
}
