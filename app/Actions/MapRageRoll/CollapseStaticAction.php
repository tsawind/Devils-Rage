<?php

declare(strict_types=1);

namespace App\Actions\MapRageRoll;

use App\Actions\MapSolarsystem\HideOldChainsAction;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\ChainMemory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Patch 57: the static collapsed (the roll timer started): its old chain goes at once instead of
 * waiting for "New Alpha?". The chain's names get the stamp ("Alpha" → "ALPHA@1958"), the pipe
 * goes, and the chain hides unless a pilot is still in it, it's pinned or it has another way out
 * (chain memory, patch 36). The static's signature in the rolling system stays (unlinked) so the
 * next paste still asks "New Alpha?" and the new hole takes the name.
 *
 * Which pipe: the one the collapsing pilot came back through, else the rolling system's only
 * static that has a pipe. Two statics and no pilot to tell them apart: nothing is removed.
 */
final readonly class CollapseStaticAction
{
    public function __construct(
        private NewStaticAction $newStaticAction,
        private HideOldChainsAction $hideOldChainsAction,
        private MapBroadcaster $mapBroadcaster,
    ) {}

    /**
     * @return array{alias: string|null, hidden: int}|null null when no old chain was found
     *
     * @throws Throwable
     */
    public function handle(Map $map, ?int $fromSolarsystemId = null): ?array
    {
        $rolling = MapSolarsystem::query()
            ->where('map_id', $map->id)
            ->where('solarsystem_id', $map->rage_roll_solarsystem_id)
            ->first();
        if ($rolling === null) {
            return null;
        }

        $connection = $this->collapsedPipe($rolling, $fromSolarsystemId);
        if ($connection === null) {
            return null;
        }
        $farId = $connection->from_map_solarsystem_id === $rolling->id ? $connection->to_map_solarsystem_id : $connection->from_map_solarsystem_id;
        $far = MapSolarsystem::query()->find($farId);
        if ($far === null) {
            return null;
        }

        $alias = DB::transaction(function () use ($rolling, $connection, $far): ?string {
            $signature = Signature::query()
                ->where('map_solarsystem_id', $rolling->id)
                ->where('map_connection_id', $connection->id)
                ->first();
            $base = mb_strtoupper(mb_trim((string) ($signature->alias ?? $far->alias ?? '')));

            if ($base !== '' && ! str_contains($base, ChainMemory::STAMP)) {
                $this->newStaticAction->stampChain($rolling, $base, $far->created_at->utc()->format('Hi'));
            }
            // Keep our side's signature (the pipe's delete would take it with it).
            $signature?->update(['map_connection_id' => null]);
            $connection->delete();

            return $base !== '' ? $base : null;
        });

        $hidden = $this->hideOldChainsAction->handle($map, [$far->id]);
        $this->mapBroadcaster->resync($map->id);

        return ['alias' => $alias, 'hidden' => count($hidden)];
    }

    private function collapsedPipe(MapSolarsystem $rolling, ?int $fromSolarsystemId): ?MapConnection
    {
        $touching = fn (Builder $query): Builder => $query
            ->where('from_map_solarsystem_id', $rolling->id)
            ->orWhere('to_map_solarsystem_id', $rolling->id);

        if ($fromSolarsystemId !== null) {
            $from = MapSolarsystem::query()->where('map_id', $rolling->map_id)->where('solarsystem_id', $fromSolarsystemId)->first();
            if ($from === null || $from->id === $rolling->id) {
                return null;
            }

            $connection = MapConnection::query()
                ->where('map_id', $rolling->map_id)
                ->where(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $pair): Builder => $pair->where('from_map_solarsystem_id', $rolling->id)->where('to_map_solarsystem_id', $from->id))
                    ->orWhere(fn (Builder $pair): Builder => $pair->where('from_map_solarsystem_id', $from->id)->where('to_map_solarsystem_id', $rolling->id)))
                ->first();
            // Only a static's pipe: coming back through a wandering hole removes nothing.
            $ours = $connection === null ? null : Signature::query()->where('map_solarsystem_id', $rolling->id)->where('map_connection_id', $connection->id)->first();

            return $ours !== null && ! $ours->is_static ? null : $connection;
        }

        $statics = Signature::query()
            ->where('map_solarsystem_id', $rolling->id)
            ->where('is_static', true)
            ->whereNotNull('map_connection_id')
            ->pluck('map_connection_id');
        if ($statics->count() !== 1) {
            return null;
        }

        return MapConnection::query()->where('map_id', $rolling->map_id)->where($touching)->find($statics->first());
    }
}
