<?php
/**
 * Maps `leads` columns to the CSV header text used when exporting for
 * Saleshandy's bulk prospect import. Order here is the column order in
 * the exported file.
 *
 * NOTE: these headers use the same plain-English labels as the source
 * data (see app/config/constants.php LEAD_FIELDS) rather than Saleshandy's
 * internal field keys, because Saleshandy's own import wizard lets you
 * map arbitrary CSV column headers to its system fields (or to a custom
 * field) during import -- so this export works as-is without needing to
 * guess Saleshandy's exact internal names. If you'd rather the columns
 * come in pre-matched to Saleshandy's system fields, open Saleshandy ->
 * Prospects -> Import -> download its blank CSV template, and rename the
 * values on the right below to match its header row exactly.
 */

return [
    'email' => 'Email',
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'title' => 'Title',
    'na_company_name' => 'Company Name',
    'company_name_for_emails' => 'Company Name for Emails',
    'person_linkedin_url' => 'Person Linkedin Url',
    'website' => 'Website',
    'company_linkedin_url' => 'Company Linkedin Url',
    'city' => 'City',
    'state' => 'State',
    'country' => 'Country',
    'company_phone' => 'Company Phone',
    'industry' => 'Industry',
    'seniority' => 'Seniority',
    'departments' => 'Departments',
    'keywords' => 'Keywords',
];
