<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

/**
 * Patch 74 (Tsa, 18 Nov): what a character shares, in plain groups of ESI permissions. Devils
 * share everything; an ally starts from a small template (location, skills, fittings, fleets,
 * killmails; 19 Nov); partial is the pilot's own ticks. Only ticked permissions are ever asked for.
 */
final class AccessScopes
{
    /** Group key => [label, what it's for]. */
    public const GROUPS = [
        'location' => ['Location and ship', 'Where it is, what it flies, whether it is online (SeAT\'s own tracking; the mapper asks for its own when you add it there)'],
        'skills' => ['Skills, queue, clones and implants', 'Skill plans, training, jump clones'],
        'fittings' => ['Fittings', 'Its saved fits'],
        'fleets' => ['Fleets', 'Which fleet it is in, for the fleet tools'],
        'killmails' => ['Killmails', 'Its kills and losses'],
        'planets' => ['Planets (PI)', 'Its colonies and customs offices'],
        'mail' => ['Mail, contacts and calendar', 'EVE mail, contacts, events'],
        'assets' => ['Assets, wallet, market and industry', 'What it owns, its ISK, orders, contracts, jobs and blueprints'],
        'corp' => ['Corp roles and corp data', 'For officers and directors: corp wallets, assets, structures, members'],
        'other' => ['Everything else', 'Notifications, standings, loyalty points, medals, titles, jump fatigue, chat channels, research agents'],
    ];

    /** The ally template: what an ally shares to start with (they tick more if they like). */
    public const ALLY_GETS = ['location', 'skills', 'fittings', 'fleets', 'killmails'];

    /** The group an ESI permission belongs to. */
    public static function groupOf(string $scope): string
    {
        return match (true) {
            str_contains($scope, 'corporation') || str_starts_with($scope, 'esi-corporations.') || str_starts_with($scope, 'esi-alliances.') => 'corp',
            str_starts_with($scope, 'esi-location.') || $scope === 'esi-universe.read_structures.v1' || $scope === 'esi-search.search_structures.v1' => 'location',
            str_starts_with($scope, 'esi-skills.') || str_starts_with($scope, 'esi-clones.') => 'skills',
            str_starts_with($scope, 'esi-assets.') || str_starts_with($scope, 'esi-wallet.') || str_starts_with($scope, 'esi-markets.') || str_starts_with($scope, 'esi-contracts.') || str_starts_with($scope, 'esi-industry.') || $scope === 'esi-characters.read_blueprints.v1' => 'assets',
            str_starts_with($scope, 'esi-fittings.') => 'fittings',
            str_starts_with($scope, 'esi-fleets.') => 'fleets',
            str_starts_with($scope, 'esi-killmails.') => 'killmails',
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
            'ally' => self::ALLY_GETS,
            default => [],
        };
    }
}
