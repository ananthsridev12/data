<?php

const ROLE_ADMIN = 'admin';
const ROLE_MEMBER = 'member';

/**
 * Every column the `leads` table accepts, in a stable display order.
 * 'required' drives both the mapping-screen grouping and row validation
 * on import. 'label' is shown in the UI (mapping dropdowns, dashboard
 * filters).
 */
const LEAD_FIELDS = [
    'na_company_name'         => ['label' => 'NA Company Name',            'required' => true],
    'category'                => ['label' => 'Category',                   'required' => true],
    'products'                => ['label' => 'Products',                   'required' => true],
    'first_name'              => ['label' => 'First Name',                 'required' => true],
    'last_name'               => ['label' => 'Last Name',                  'required' => true],
    'title'                   => ['label' => 'Title',                      'required' => true],
    'company_name_for_emails' => ['label' => 'Company Name for Emails',    'required' => true],
    'email'                   => ['label' => 'Email',                      'required' => true],
    'seniority'               => ['label' => 'Seniority',                  'required' => false],
    'departments'             => ['label' => 'Departments',                'required' => false],
    'sub_departments'         => ['label' => 'Sub Departments',            'required' => false],
    'employee_count'          => ['label' => '# Employees',                'required' => false],
    'industry'                => ['label' => 'Industry',                   'required' => true],
    'keywords'                => ['label' => 'Keywords',                   'required' => false],
    'person_linkedin_url'     => ['label' => 'Person Linkedin Url',        'required' => true],
    'website'                 => ['label' => 'Website',                    'required' => true],
    'company_linkedin_url'    => ['label' => 'Company Linkedin Url',       'required' => true],
    'facebook_url'            => ['label' => 'Facebook Url',               'required' => false],
    'twitter_url'             => ['label' => 'Twitter Url',                'required' => false],
    'city'                    => ['label' => 'City',                      'required' => false],
    'state'                   => ['label' => 'State',                     'required' => false],
    'country'                 => ['label' => 'Country',                   'required' => false],
    'company_address'         => ['label' => 'Company Address',           'required' => false],
    'company_city'            => ['label' => 'Company City',              'required' => false],
    'company_state'           => ['label' => 'Company State',             'required' => false],
    'company_country'         => ['label' => 'Company Country',           'required' => true],
    'company_phone'           => ['label' => 'Company Phone',             'required' => false],
    'technologies'            => ['label' => 'Technologies',              'required' => false],
    'annual_revenue'          => ['label' => 'Annual Revenue',            'required' => false],
    'total_funding'           => ['label' => 'Total Funding',             'required' => false],
    'latest_funding'          => ['label' => 'Latest Funding',            'required' => false],
    'latest_funding_amount'   => ['label' => 'Latest Funding Amount',     'required' => false],
    'last_raised_at'          => ['label' => 'Last Raised At',            'required' => false],
];

function lead_required_fields(): array
{
    return array_keys(array_filter(LEAD_FIELDS, static fn(array $f): bool => $f['required']));
}

/**
 * Known source header text (as seen in real provider exports) mapped to a
 * `leads` column, used to auto-suggest the import mapping. Matching is
 * done case/whitespace-insensitively (see ImportMapper::normalizeHeader).
 * Note "Country" appears twice in the source field list: the first plain
 * "Country" occurrence is treated as the person-level column, the later
 * one appearing alongside Company Address/City/State (also labeled
 * "Required" in the source) as the company-level column. Because both
 * source headers are the literal text "Country", this table cannot
 * disambiguate them by name alone -- ImportMapper resolves the first
 * occurrence to `country` and the second to `company_country`
 * positionally, and the mapping screen always lets the admin override it
 * per file.
 */
const HEADER_ALIASES = [
    'na company name'          => 'na_company_name',
    'company name'              => 'na_company_name',
    'category'                  => 'category',
    'products'                  => 'products',
    'first name'                => 'first_name',
    'last name'                 => 'last_name',
    'title'                     => 'title',
    'company name for emails'  => 'company_name_for_emails',
    'email'                     => 'email',
    'seniority'                 => 'seniority',
    'departments'               => 'departments',
    'sub departments'           => 'sub_departments',
    '# employees'               => 'employee_count',
    'employees'                 => 'employee_count',
    'industry'                  => 'industry',
    'keywords'                  => 'keywords',
    'person linkedin url'       => 'person_linkedin_url',
    'website'                   => 'website',
    'company linkedin url'      => 'company_linkedin_url',
    'facebook url'              => 'facebook_url',
    'twitter url'                => 'twitter_url',
    'city'                       => 'city',
    'state'                      => 'state',
    'company address'            => 'company_address',
    'company city'                => 'company_city',
    'company state'               => 'company_state',
    'company phone'                => 'company_phone',
    'technologies'                  => 'technologies',
    'annual revenue'                => 'annual_revenue',
    'total funding'                  => 'total_funding',
    'latest funding'                  => 'latest_funding',
    'latest funding amount'           => 'latest_funding_amount',
    'last raised at'                   => 'last_raised_at',
    // 'country' is intentionally absent here: it's resolved positionally
    // in ImportMapper because the same header text is used twice.
];
