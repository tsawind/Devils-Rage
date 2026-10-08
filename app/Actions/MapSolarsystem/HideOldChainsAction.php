<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\CharacterStatus;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Support\ChainMemory;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Patch 36: an old chain (one that carries a "@hhmm" stamp, e.g. after a rage
 * roll's "New Alpha?" → Yes) that no longer hangs off the map hides, unless a
 * pilot is still inside it, one of its systems is kept (pinned, home, rally
 * point, rage home), or it still has another way out (a k-space system). Then
 * it stays in the side-chain band until the last of those goes; the cleanup
 * command checks again every run.
 */
final readonly class HideOldChainsAction
{
    public function __construct(private HideMapSolarsystemsAction $hideMapSolarsystemsAction) {}

    /**
     * @param  list<int>|null  $start_ids  only look at the chains these systems are in
     * @return list<int> the systems hidden
     *
     * @throws Throwable
     */
    public function handle(Map $map, ?array $start_ids = null): array
    {
        /** @var Collection<int, MapSolarsystem> $systems */
        $systems = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->with('solarsystem:id,type')
            ->get(['id', 'map_id', 'solarsystem_id', 'alias', 'pinned', 'combat_home']);

        if ($systems->isEmpty()) {
            return [];
        }

        $adjacency = [];
        MapConnection::query()
            ->where('map_id', $map->id)
            ->get(['from_map_solarsystem_id', 'to_map_solarsystem_id'])
            ->each(function (MapConnection $connection) use (&$adjacency): void {
                $adjacency[(int) $connection->from_map_solarsystem_id][] = (int) $connection->to_map_solarsystem_id;
                $adjacency[(int) $connection->to_map_solarsystem_id][] = (int) $connection->from_map_solarsystem_id;
            });

        $by_id = $systems->keyBy('id');
        $seeds = $start_ids ?? $systems
            ->filter(fn (MapSolarsystem $system): bool => ChainMemory::isStamped($system->alias))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $occupied = $this->occupiedSolarsystemIds($systems);
        $seen = [];
        $to_hide = [];

        foreach ($seeds as $seed) {
            if (isset($seen[$seed]) || ! $by_id->has($seed)) {
                continue;
            }

            $component = self::component($seed, $adjacency);
            foreach ($component as $id) {
                $seen[$id] = true;
            }

            /** @var Collection<int, MapSolarsystem> $members */
            $members = $by_id->only($component)->values();
            if (self::staysVisible($map, $members, $occupied)) {
                continue;
            }

            array_push($to_hide, ...$component);
        }

        return $to_hide === [] ? [] : $this->hideMapSolarsystemsAction->handle($map, array_values(array_unique($to_hide)), automatic: true);
    }

    /**
     * @param  Collection<int, MapSolarsystem>  $members
     * @param  array<int, true>  $occupied  solarsystem ids with an online pilot
     */
    public static function staysVisible(Map $map, Collection $members, array $occupied): bool
    {
        foreach ($members as $system) {
            if (ChainMemory::isProtected($map, $system)) {
                return true;
            }
            if (isset($occupied[$system->solarsystem_id])) {
                return true;
            }
            if ($system->solarsystem->type === 'eve') {
                return true;
            }
        }

        // Only old (stamped) chains hide by themselves; side chains started elsewhere stay.
        return ! $members->contains(fn (MapSolarsystem $system): bool => ChainMemory::isStamped($system->alias));
    }

    /**
     * Every system reachable from `seed` through the map's pipes.
     *
     * @param  array<int, list<int>>  $adjacency
     * @return list<int>
     */
    public static function component(int $seed, array $adjacency): array
    {
        $reached = [];
        $queue = [$seed];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reached[$current])) {
                continue;
            }
            $reached[$current] = true;
            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reached[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return array_keys($reached);
    }

    /**
     * Solarsystems on this chain with an online pilot in them.
     *
     * @param  Collection<int, MapSolarsystem>  $systems
     * @return array<int, true>
     */
    private function occupiedSolarsystemIds(Collection $systems): array
    {
        return CharacterStatus::query()
            ->where('is_online', true)
            ->whereIn('solarsystem_id', $systems->pluck('solarsystem_id')->all())
            ->pluck('solarsystem_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }
}
