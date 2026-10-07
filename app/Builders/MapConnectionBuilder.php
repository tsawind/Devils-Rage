<?php

declare(strict_types=1);

namespace App\Builders;

use App\Enums\LifetimeStatus;
use App\Models\CharacterStatus;
use App\Models\MapConnection;
use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin CharacterStatus
 *
 * @template T of MapConnection
 *
 * @extends Builder<T>
 */
final class MapConnectionBuilder extends Builder
{
    public function connectsToWormhole(): self
    {
        return $this->where(
            fn (Builder $query) => $query
                ->whereHas('fromMapSolarsystem', fn (Builder $query) => $query->whereHas('wormholeSystem'))
                ->orWhereHas('toMapSolarsystem', fn (Builder $query) => $query->whereHas('wormholeSystem'))
        );
    }

    public function isNotTimeCritical(): self
    {
        return $this->where('lifetime', '!=', LifetimeStatus::Critical);
    }

    public function isStale(): self
    {
        return $this->where('lifetime', LifetimeStatus::Critical)
            ->whereNotNull('lifetime_updated_at')
            ->where('lifetime_updated_at', '<=', now()->subHour());
    }

    /**
     * Load the jump-log aggregates and the recent jumps every
     * MapConnectionResource serialization expects.
     */
    public function withJumpSummary(): self
    {
        return $this
            ->withCount('jumps')
            ->withSum('jumps as jumps_mass_sum', 'mass')
            // Patch 32: only the jumps since the mass status was last set count against its band.
            ->withSum(['jumps as jumps_mass_since_status' => fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('map_connections.mass_status_updated_at')
                ->orWhereColumn('map_connection_jumps.created_at', '>=', 'map_connections.mass_status_updated_at'))], 'mass')
            ->with(['jumps' => fn ($query) => $query
                ->with('character:id,name', 'shipType:id,name')
                ->latest('id')
                ->limit(10),
            ]);
    }

    public function connectsSolarsystemsInMap(int $map_id, int $first_solarsystem_id, int $second_solarsystem_id): self
    {
        return $this->whereRelation('map', 'id', $map_id)
            ->where(
                fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query
                        ->whereRelation('fromMapSolarsystem', 'solarsystem_id', $first_solarsystem_id)
                        ->whereRelation('toMapSolarsystem', 'solarsystem_id', $second_solarsystem_id)
                    )
                    ->orWhere(fn (Builder $query) => $query
                        ->whereRelation('fromMapSolarsystem', 'solarsystem_id', $second_solarsystem_id)
                        ->whereRelation('toMapSolarsystem', 'solarsystem_id', $first_solarsystem_id)
                    )
            );
    }
}
