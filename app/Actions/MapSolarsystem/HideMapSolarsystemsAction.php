<?php

declare(strict_types=1);

namespace App\Actions\MapSolarsystem;

use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Patch 36: chain memory. Clears hide systems instead of deleting them. A
 * hidden system keeps its row, notes and signatures; its pipes go (their
 * holes are gone from the map), and its name gets a "@hhmm" stamp so it never
 * blocks a new system of the same name. Reconnecting within 27 h brings it
 * back as it was ({@see ChainMemory::unhide()}).
 */
final readonly class HideMapSolarsystemsAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @param  list<int>  $map_solarsystem_ids
     * @param  bool  $automatic  cleanup / rage roll: also keeps the rally point, the rolling system and rage homes
     * @return list<int> the systems hidden
     *
     * @throws Throwable
     */
    public function handle(Map $map, array $map_solarsystem_ids, bool $automatic = false, bool $broadcast = true): array
    {
        if ($map_solarsystem_ids === []) {
            return [];
        }

        [$hidden_ids, $connection_ids] = DB::transaction(function () use ($map, $map_solarsystem_ids, $automatic): array {
            $systems = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->whereIn('id', $map_solarsystem_ids)
                ->lockForUpdate()
                ->get()
                ->reject(fn (MapSolarsystem $system): bool => $automatic
                    ? ChainMemory::isProtected($map, $system)
                    : ChainMemory::isAlwaysKept($map, $system))
                ->values();

            if ($systems->isEmpty()) {
                return [[], []];
            }

            $ids = $systems->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            $others = MapSolarsystem::query()
                ->where('map_id', $map->id)
                ->whereNotIn('id', $ids)
                ->whereNotNull('alias')
                ->get(['id', 'alias', 'created_at']);
            $stamps = ChainMemory::stampsFor($systems, $others);

            $connection_ids = MapConnection::query()
                ->where('map_id', $map->id)
                ->where(fn (Builder $query) => $query
                    ->whereIn('from_map_solarsystem_id', $ids)
                    ->orWhereIn('to_map_solarsystem_id', $ids))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            // The hidden systems' own signatures stay (as unjumped holes); the pipes go.
            if ($connection_ids !== []) {
                Signature::query()
                    ->whereIn('map_solarsystem_id', $ids)
                    ->whereIn('map_connection_id', $connection_ids)
                    ->update(['map_connection_id' => null]);
                MapConnection::query()->whereIn('id', $connection_ids)->delete();
            }

            $now = CarbonImmutable::now();
            foreach ($systems as $system) {
                $stamp = $stamps[$system->id] ?? null;
                $system->forceFill([
                    'hidden_at' => $now,
                    'alias' => $stamp === null ? $system->alias : mb_strtoupper(mb_trim((string) $system->alias)).'@'.$stamp,
                    'combat_color' => null,
                    'combat_home' => false,
                    'combat_active' => false,
                    'combat_started_at' => null,
                    'combat_previous_color' => null,
                    'cleanup_return_pending' => false,
                ])->save();

                Signature::query()
                    ->where('map_solarsystem_id', $system->id)
                    ->whereNotNull('armed_by_user_id')
                    ->update(['armed_by_user_id' => null, 'armed_by_name' => null, 'armed_at' => null, 'armed_claimed_alias' => false]);

                if ($stamp !== null) {
                    Signature::query()
                        ->where('map_solarsystem_id', $system->id)
                        ->whereNotNull('alias')
                        ->where('alias', 'not like', '%@%')
                        ->get()
                        ->each(fn (Signature $signature) => $signature->update(['alias' => mb_strtoupper(mb_trim((string) $signature->alias)).'@'.$stamp]));
                }
            }

            return [$ids, $connection_ids];
        });

        if ($broadcast && $hidden_ids !== []) {
            $this->mapBroadcaster->systemsRemoved($map->id, $hidden_ids, $connection_ids);
        }

        return $hidden_ids;
    }
}
