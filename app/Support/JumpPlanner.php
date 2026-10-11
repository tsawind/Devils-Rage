<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Solarsystem;
use Illuminate\Support\Facades\Cache;
use NicolasKion\SDE\Models\SolarsystemConnection;

/**
 * Patch 37: jumping into k-space with a jump drive (Black Ops bridge, carrier conduit jump).
 *
 * - Range: the hull's base range × (1 + 20% per Jump Drive Calibration level):
 *   carriers 3.5 LY (6.3 at IV, 7.0 at V), Black Ops 4.0 LY (7.2 at IV, 8.0 at V).
 * - A conduit jump takes 5 ships, plus 5 per Capital Jump Portal Generation level (30 at V);
 *   a command carrier (Salvation, Simurgh, Gaia, Ymir) takes 20% more per level of its
 *   racial Carrier skill (60 at V/V).
 * - No cyno in highsec, wormhole space or Pochven; a jump drive can't start in highsec.
 */
final class JumpPlanner
{
    public const float METERS_PER_LIGHTYEAR = 9_460_730_472_580_800.0;

    public const float CARRIER_BASE_LY = 3.5;

    public const float BLOPS_BASE_LY = 4.0;

    /** Below this Jump Drive Calibration level the pilot is told to train or inject it. */
    public const int JDC_ADVISED = 4;

    /** Gate jumps searched from an exit (or the target) for a usable system. */
    public const int MAX_GATES = 12;

    public const array COMMAND_CARRIERS = ['Salvation', 'Simurgh', 'Gaia', 'Ymir'];

    private const int POCHVEN_REGION_ID = 10000070;

    private const int ZARZAKH_ID = 30100000;

    public static function range(float $base, int $jdc): float
    {
        return round($base * (1 + 0.2 * max(0, min(5, $jdc))), 2);
    }

    public static function conduitShips(int $portalGeneration, bool $commandCarrier = false, int $racialCarrier = 0): int
    {
        $ships = 5 + 5 * max(0, min(5, $portalGeneration));

        return $commandCarrier ? (int) round($ships * (1 + 0.2 * max(0, min(5, $racialCarrier)))) : $ships;
    }

    public static function isHighsec(float $security): bool
    {
        return round($security, 1) >= 0.5;
    }

    /**
     * @param  array{id: int, security: float, region_id: int}  $system
     */
    public static function canLightCyno(array $system): bool
    {
        return ! self::isHighsec($system['security'])
            && $system['id'] < 31_000_000
            && $system['id'] !== self::ZARZAKH_ID
            && $system['region_id'] !== self::POCHVEN_REGION_ID;
    }

    /**
     * @param  array{x: float, y: float, z: float}  $from
     * @param  array{x: float, y: float, z: float}  $to
     */
    public static function lightyears(array $from, array $to): float
    {
        return sqrt(($from['x'] - $to['x']) ** 2 + ($from['y'] - $to['y']) ** 2 + ($from['z'] - $to['z']) ** 2) / self::METERS_PER_LIGHTYEAR;
    }

    /**
     * Every k-space system: id → name, security, region and position. Cached for a day.
     *
     * @return array<int, array{id: int, name: string, security: float, region_id: int, x: float, y: float, z: float}>
     */
    public static function systems(): array
    {
        /** @var array<int, array{id: int, name: string, security: float, region_id: int, x: float, y: float, z: float}> */
        return Cache::remember('jump-planner:systems', now()->addDay(), fn (): array => Solarsystem::query()
            ->where('id', '<', 31_000_000)
            ->get(['id', 'name', 'security', 'region_id', 'pos_x', 'pos_y', 'pos_z'])
            ->mapWithKeys(fn (Solarsystem $system): array => [(int) $system->id => [
                'id' => (int) $system->id,
                'name' => (string) $system->name,
                'security' => (float) $system->security,
                'region_id' => (int) $system->region_id,
                'x' => (float) $system->pos_x,
                'y' => (float) $system->pos_y,
                'z' => (float) $system->pos_z,
            ]])
            ->all());
    }

    /**
     * Gate neighbours: id → list of ids. Cached for a day.
     *
     * @return array<int, list<int>>
     */
    public static function gates(): array
    {
        /** @var array<int, list<int>> */
        return Cache::remember('jump-planner:gates', now()->addDay(), function (): array {
            $neighbours = [];
            SolarsystemConnection::query()
                ->get(['from_solarsystem_id', 'to_solarsystem_id'])
                ->each(function (SolarsystemConnection $connection) use (&$neighbours): void {
                    $neighbours[(int) $connection->from_solarsystem_id][] = (int) $connection->to_solarsystem_id;
                });

            return $neighbours;
        });
    }

    /**
     * The nearest system by gates from $startId (itself included) that passes $accept.
     *
     * @param  array<int, array{id: int, name: string, security: float, region_id: int, x: float, y: float, z: float}>  $systems
     * @param  array<int, list<int>>  $gates
     * @param  callable(array{id: int, name: string, security: float, region_id: int, x: float, y: float, z: float}): bool  $accept
     * @return array{id: int, name: string, gates: int}|null
     */
    public static function nearest(int $startId, array $systems, array $gates, callable $accept): ?array
    {
        $seen = [$startId => 0];
        $queue = [$startId];
        while ($queue !== []) {
            $id = array_shift($queue);
            $depth = $seen[$id];
            if (isset($systems[$id]) && $accept($systems[$id])) {
                return ['id' => $id, 'name' => $systems[$id]['name'], 'gates' => $depth];
            }
            if ($depth >= self::MAX_GATES) {
                continue;
            }
            foreach ($gates[$id] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = $depth + 1;
                    $queue[] = $next;
                }
            }
        }

        return null;
    }

    /**
     * Where to jump to: the target itself when a cyno can be lit there, else the nearest
     * system by gates where one can (highsec target → the nearest lowsec).
     *
     * @return array{id: int, name: string, gates: int}|null
     */
    public static function landing(int $targetId): ?array
    {
        return self::nearest($targetId, self::systems(), self::gates(), self::canLightCyno(...));
    }

    /**
     * From one exit of the chain: jump straight from it (in range, not highsec), or the
     * nearest system by gates from it that is in range of the landing and can start a jump.
     *
     * @return array{from: string, from_id: int, ly: float, direct: bool, via: array{id: int, name: string, gates: int}|null, highsec: bool}|null
     */
    public static function fromExit(int $exitId, int $landingId, float $range): ?array
    {
        $systems = self::systems();
        if (! isset($systems[$exitId], $systems[$landingId])) {
            return null;
        }
        $landing = $systems[$landingId];
        $exit = $systems[$exitId];
        $ly = round(self::lightyears($exit, $landing), 1);
        $canStart = fn (array $system): bool => ! self::isHighsec($system['security']) && self::lightyears($system, $landing) <= $range;
        $direct = $canStart($exit);

        return [
            'from' => $exit['name'],
            'from_id' => $exitId,
            'ly' => $ly,
            'direct' => $direct,
            'via' => $direct ? null : self::nearest($exitId, $systems, self::gates(), $canStart),
            'highsec' => self::isHighsec($exit['security']),
        ];
    }
}
