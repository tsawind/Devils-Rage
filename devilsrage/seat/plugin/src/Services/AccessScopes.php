<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

/**
 * Patch 74 (Tsa, 18 Nov): what a character shares, in plain groups of ESI permissions. Devils
 * share everything; an ally starts from a template (everything but assets and corp-officer
 * data); partial is the pilot's own ticks. Only ticked permissions are ever asked for.
 */
final class AccessScopes
{
    /** Group key => [label, what it's for]. */
    public const GROUPS = [
        'location' => ['Location and ship', 'Where it is and what it flies: the mapper needs this'],
        'skills' => ['Skills, queue, clones and implants', 'Skill plans, training, jump clones'],
        'assets' => ['Assets, wallet, market and industry', 'What it owns and makes'],
        'fittings' => ['Fittings', 'Its saved fits'],
        'planets' => ['Planets (PI)', 'Its colonies'],
        'mail' => ['Mail, contacts and calendar', 'EVE mail, contacts, events'],
        'corp' => ['Corp roles and corp data', 'For officers and directors: corp wallets, assets, structures, members'],
        'other' => ['Everything else', 'Killmails, notifications, standings, fleets, opening windows in EVE'],
    ];

    /** The ally template: everything except these. */
    public const ALLY_LEAVES_OUT = ['assets', 'corp'];

    /** The group an ESI permission belongs to. */
    public static function groupOf(string $scope): string
    {
        return match (true) {
            str_contains($scope, 'corporation') || str_starts_with($scope, 'esi-corporations.') || str_starts_with($scope, 'esi-alliances.') => 'corp',
            str_starts_with($scope, 'esi-location.') => 'location',
            str_starts_with($scope, 'esi-skills.') || str_starts_with($scope, 'esi-clones.') => 'skills',
            str_starts_with($scope, 'esi-assets.') || str_starts_with($scope, 'esi-wallet.') || str_starts_with($scope, 'esi-markets.') || str_starts_with($scope, 'esi-contracts.') || str_starts_with($scope, 'esi-industry.') => 'assets',
            str_starts_with($scope, 'esi-fittings.') => 'fittings',
            str_starts_with($scope, 'esi-planets.') => 'planets',
            str_starts_with($scope, 'esi-mail.') || str_contains($scope, 'contacts') || str_starts_with($scope, 'esi-calendar.') => 'mail',
            default => 'other',
        };
    }

    /**
     * The groups SeAT's permissions fall into, each with its permissions.
     *
     * @param  list<string>  $all
     * @return array<string, list<string>>
     */
    public static function grouped(array $all): array
    {
        $out = array_fill_keys(array_keys(self::GROUPS), []);
        foreach ($all as $scope) {
            if ($scope !== 'publicData') {
                $out[self::groupOf($scope)][] = $scope;
            }
        }

        return array_filter($out);
    }

    /**
     * The permissions for these ticked groups (only ones SeAT asks for).
     *
     * @param  list<string>  $groups
     * @param  list<string>  $all
     * @return list<string>
     */
    public static function scopesFor(array $groups, array $all): array
    {
        $grouped = self::grouped($all);
        $out = [];
        foreach ($groups as $group) {
            array_push($out, ...($grouped[$group] ?? []));
        }

        return array_values(array_unique($out));
    }

    /**
     * The groups a set of permissions covers (every permission of the group).
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $all
     * @return list<string>
     */
    public static function groupsOf(array $scopes, array $all): array
    {
        $out = [];
        foreach (self::grouped($all) as $group => $list) {
            if ($list !== [] && array_diff($list, $scopes) === []) {
                $out[] = $group;
            }
        }

        return $out;
    }

    /**
     * The template groups for a level.
     *
     * @return list<string>
     */
    public static function template(string $level): array
    {
        return match ($level) {
            'devil' => array_keys(self::GROUPS),
            'ally' => array_values(array_diff(array_keys(self::GROUPS), self::ALLY_LEAVES_OUT)),
            default => [],
        };
    }
}
