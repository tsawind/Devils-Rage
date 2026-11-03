<?php

declare(strict_types=1);

namespace App\Actions\Fleets;

/**
 * Patch 72: a fleet for your own characters in one click, sorted by the ship each is sitting in:
 * logi in Logi, dictors / HICs / interceptors in Tackle, covert ops and recons in Scouts, command
 * ships in Boosts, haulers, miners, and the rest in DPS. One wing, one squad per role that's
 * there; the result is a layout BuildFleetAction can build (and build again after a roll: anyone
 * already in place is left alone, the rest are invited or moved).
 */
final class QuickFleet
{
    /** Role → squad name (EVE allows up to 10 characters). */
    public const SQUADS = [
        'dps' => 'DPS',
        'logi' => 'Logi',
        'tackle' => 'Tackle',
        'scout' => 'Scouts',
        'boost' => 'Boosts',
        'hauler' => 'Haulers',
        'miner' => 'Miners',
    ];

    private const GROUPS = [
        'Logistics' => 'logi',
        'Logistics Frigate' => 'logi',
        'Force Auxiliary' => 'logi',
        'Interdictor' => 'tackle',
        'Heavy Interdiction Cruiser' => 'tackle',
        'Interceptor' => 'tackle',
        'Covert Ops' => 'scout',
        'Force Recon Ship' => 'scout',
        'Combat Recon Ship' => 'scout',
        'Expedition Frigate' => 'scout',
        'Shuttle' => 'scout',
        'Capsule' => 'scout',
        'Command Ship' => 'boost',
        'Command Destroyer' => 'boost',
        'Industrial Command Ship' => 'boost',
        'Industrial' => 'hauler',
        'Blockade Runner' => 'hauler',
        'Deep Space Transport' => 'hauler',
        'Freighter' => 'hauler',
        'Mining Barge' => 'miner',
        'Exhumer' => 'miner',
    ];

    /** Tech 1 hulls that are logi (their group just says Cruiser / Frigate). */
    private const LOGI_HULLS = ['Augoror', 'Osprey', 'Exequror', 'Scythe', 'Inquisitor', 'Bantam', 'Navitas', 'Burst', 'Rodiva', 'Zarmazd', 'Thalia', 'Kirin', 'Deacon', 'Scalpel'];

    /** The role for a ship, from its group and hull name (unknown ship: DPS). */
    public static function roleFor(?string $group, ?string $type): string
    {
        if ($type !== null && in_array($type, self::LOGI_HULLS, true)) {
            return 'logi';
        }

        return self::GROUPS[(string) $group] ?? 'dps';
    }

    /**
     * The layout for these characters, squads in a fixed order (DPS first).
     *
     * @param  list<array{id: int, name: string, role: string}>  $members
     * @return array{wings: list<array{name: string, commander: null, squads: list<array{name: string, commander: null, members: list<array{id: int, name: string}>}>}>}
     */
    public static function layout(array $members, string $wingName = 'Fleet'): array
    {
        $squads = [];
        foreach (array_keys(self::SQUADS) as $role) {
            $in = array_values(array_filter($members, fn (array $m): bool => (isset(self::SQUADS[$m['role']]) ? $m['role'] : 'dps') === $role));
            if ($in === []) {
                continue;
            }
            $squads[] = [
                'name' => self::SQUADS[$role],
                'commander' => null,
                'members' => array_map(fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name']], $in),
            ];
        }

        return ['wings' => [['name' => mb_substr($wingName, 0, 10), 'commander' => null, 'squads' => $squads]]];
    }
}
