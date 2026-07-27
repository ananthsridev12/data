<?php

/**
 * Exact-match (case-insensitive) classifier for leads.company_country ->
 * country_group_id. Mirrors RoleGroupClassifier's structure (an ordered
 * list of groups, each with a comma-separated raw-value list, first
 * match wins) but uses exact rather than substring matching -- country
 * names/codes are standardized enough that substring matching risks
 * over-matching short codes (e.g. a substring match on "US" would also
 * hit "USA", "Belarus", "Australia", etc.), unlike free-text job titles.
 */
class CountryGroupClassifier
{
    /**
     * @param array<int,array{id:int|string,countries:?string}> $groups tried in the order given
     */
    public static function classify(?string $companyCountry, array $groups): ?int
    {
        if ($companyCountry === null || trim($companyCountry) === '') {
            return null;
        }
        $normalized = mb_strtolower(trim($companyCountry));

        foreach ($groups as $group) {
            foreach (self::parseCountries($group['countries'] ?? '') as $country) {
                if (mb_strtolower($country) === $normalized) {
                    return (int) $group['id'];
                }
            }
        }
        return null;
    }

    /** @return array<int,string> */
    public static function parseCountries(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $c): bool => $c !== ''));
    }
}
