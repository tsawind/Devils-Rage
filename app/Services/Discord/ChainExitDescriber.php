<?php

declare(strict_types=1);

namespace App\Services\Discord;

use App\Enums\SolarsystemClass;
use App\Models\Solarsystem;
use App\Services\Routing\MapProximityPathfinder;

/**
 * One Discord line about a k-space exit: security, region and the nearest trade hub by gates.
 */
final readonly class ChainExitDescriber
{
    /** Trade hub solar system IDs => names. */
    public const array HUBS = [
        30000142 => 'Jita',
        30002187 => 'Amarr',
        30002659 => 'Dodixie',
        30002510 => 'Rens',
        30002053 => 'Hek',
    ];

    private const int HUB_SEARCH_JUMPS = 40;

    public function __construct(private MapProximityPathfinder $pathfinder) {}

    public function line(Solarsystem $system): string
    {
        $security = (float) $system->security;
        $class = match (SolarsystemClass::fromSecurity($security)) {
            SolarsystemClass::H => 'high-sec',
            SolarsystemClass::L => 'low-sec',
            default => 'null-sec',
        };

        return sprintf(
            '**%s** (%.1f %s · %s) · %s',
            $system->name,
            round($security, 1),
            $class,
            $system->region->name,
            $this->nearestHub($system->id),
        );
    }

    public function nearestHub(int $solarsystemId): string
    {
        $jumps = $this->pathfinder->withinJumps([$solarsystemId], [], [], self::HUB_SEARCH_JUMPS);
        $best = null;
        foreach (self::HUBS as $hubId => $name) {
            if (isset($jumps[$hubId]) && ($best === null || $jumps[$hubId] < $best[1])) {
                $best = [$name, $jumps[$hubId]];
            }
        }

        if ($best === null) {
            return 'no trade hub by gates';
        }

        return $best[1] === 0 ? 'is '.$best[0] : sprintf('%s %d %s', $best[0], $best[1], $best[1] === 1 ? 'jump' : 'jumps');
    }
}
