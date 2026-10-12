<?php

declare(strict_types=1);

namespace App\Actions\MapAlerts;

use App\Enums\MapAlertType;
use App\Jobs\MapAlerts\EvaluateMapAlertsJob;
use App\Models\MapAlert;
use App\Models\MapSolarsystem;

/**
 * A hole became XL: k-space exits further out may now be reachable by capitals, so
 * XL-only jump-range alerts look at the map's k-space systems again. Each alert still
 * fires at most once per system (the evaluation job keeps a delivery reservation).
 */
final class RecheckCapitalAlertsAction
{
    public function handle(int $mapId): void
    {
        $hasXlAlerts = MapAlert::query()
            ->where('map_id', $mapId)
            ->where('type', MapAlertType::JumpRange)
            ->where('xl_only', true)
            ->where('is_active', true)
            ->exists();

        if (! $hasXlAlerts) {
            return;
        }

        MapSolarsystem::query()
            ->where('map_id', $mapId)
            ->whereHas('solarsystem', fn ($query) => $query->where('type', 'eve'))
            ->pluck('id')
            ->each(fn (int $placementId) => EvaluateMapAlertsJob::dispatch($placementId)->afterCommit());
    }
}
