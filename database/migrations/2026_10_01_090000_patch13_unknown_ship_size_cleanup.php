<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Patch 13: tracking used to save a connection as Large when nothing told it
 * the size. C5/C6 holes are only XL or frigate, never Large, so a Large
 * connection between two C5/C6 systems with no identified hole type (other
 * than K162) was that default: it goes back to unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        $highClass = fn (string $column) => fn (Builder $query) => $query
            ->select('map_solarsystems.id')
            ->from('map_solarsystems')
            ->join('wormhole_systems', 'wormhole_systems.id', '=', 'map_solarsystems.solarsystem_id')
            ->whereIn('wormhole_systems.class', [5, 6]);

        DB::table('map_connections')
            ->where('ship_size', 'large')
            ->where('type', 'wormhole')
            ->whereIn('from_map_solarsystem_id', $highClass('from'))
            ->whereIn('to_map_solarsystem_id', $highClass('to'))
            ->whereNotIn('id', fn (Builder $query) => $query
                ->select('signatures.map_connection_id')
                ->from('signatures')
                ->join('wormholes', 'wormholes.id', '=', 'signatures.wormhole_id')
                ->whereNotNull('signatures.map_connection_id')
                ->where('wormholes.name', 'not like', 'K162%'))
            ->update(['ship_size' => null]);
    }

    public function down(): void
    {
        // The old default can't be told apart afterwards; nothing to undo.
    }
};
