<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Patch 37: holes that let 1,000M kg through per jump (M555, D792, B041,
 * B520, A641, R051, V283, B449 …) are XL in game, but the old size rule
 * (Large below 2B) saved them as Large. Saved Large signatures and
 * connections whose hole type allows more than 375M per jump become XL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bigTypes = fn (Builder $query) => $query
            ->select('wormholes.id')
            ->from('wormholes')
            ->where('wormholes.maximum_jump_mass', '>', 375_000_000);

        DB::table('signatures')
            ->where('ship_size', 'large')
            ->whereIn('wormhole_id', $bigTypes)
            ->update(['ship_size' => 'xlarge']);

        DB::table('map_connections')
            ->where('ship_size', 'large')
            ->whereIn('id', fn (Builder $query) => $query
                ->select('signatures.map_connection_id')
                ->from('signatures')
                ->whereNotNull('signatures.map_connection_id')
                ->whereIn('signatures.wormhole_id', $bigTypes))
            ->update(['ship_size' => 'xlarge']);
    }

    public function down(): void
    {
        // The old size was wrong; nothing to undo.
    }
};
