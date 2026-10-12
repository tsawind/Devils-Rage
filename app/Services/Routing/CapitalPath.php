<?php

declare(strict_types=1);

namespace App\Services\Routing;

use App\Enums\ShipSize;
use App\Models\Map;
use App\Models\MapConnection;

/**
 * Where a capital can go on a map: the systems reachable from the map's home system
 * through holes marked XL only. Holes of unknown size do not count.
 */
final class CapitalPath
{
    /**
     * @return array<int, true>|null solar system IDs (home included); null when the map has no home system
     */
    public function reachableFromHome(Map $map): ?array
    {
        $home = $map->home_solarsystem_id;
        if ($home === null) {
            return null;
        }

        $adjacency = [];
        $map->mapConnections()
            ->with(['fromMapSolarsystem:id,solarsystem_id', 'toMapSolarsystem:id,solarsystem_id'])
            ->get()
            ->each(function (MapConnection $connection) use (&$adjacency): void {
                if ($connection->ship_size !== ShipSize::ExtraLarge) {
                    return;
                }

                $from = $connection->fromMapSolarsystem->solarsystem_id;
                $to = $connection->toMapSolarsystem->solarsystem_id;

                $adjacency[$from][] = $to;
                $adjacency[$to][] = $from;
            });

        $reached = [$home => true];
        $queue = [$home];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reached[$next])) {
                    $reached[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return $reached;
    }
}
