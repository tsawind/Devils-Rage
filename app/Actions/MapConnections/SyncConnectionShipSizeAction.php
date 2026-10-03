<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Enums\ShipSize;
use App\Models\Signature;

final readonly class SyncConnectionShipSizeAction
{
    /**
     * Keep a signature's linked connection locked to the ship size dictated by
     * its identified wormhole type. A no-op for untyped or unlinked signatures.
     */
    public function handle(Signature $signature): void
    {
        if ($signature->map_connection_id === null) {
            return;
        }

        // Patch 20: a "K162 - Frigate" only lets frigates through.
        if (mb_strtolower((string) $signature->signatureType?->extra) === 'frigate') {
            $signature->mapConnection?->update(['ship_size' => ShipSize::Frigate]);

            return;
        }

        if ($signature->wormhole_id === null) {
            return;
        }

        $signature->unsetRelation('wormhole')->unsetRelation('mapConnection');

        $ship_size = ShipSize::fromWormhole($signature->wormhole);
        if (! $ship_size instanceof ShipSize) {
            return;
        }

        $signature->mapConnection?->update(['ship_size' => $ship_size]);
    }
}
