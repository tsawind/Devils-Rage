<?php

declare(strict_types=1);

namespace App\Services\Seat;

use App\Models\Solarsystem;
use App\Services\Routing\MapProximityPathfinder;

/**
 * Which of a pilot's asset systems (from SeAT) lie within a few gate jumps of k-space exits.
 */
final readonly class AssetProximity
{
    public function __construct(private MapProximityPathfinder $pathfinder) {}

    /**
     * Each asset system once, with its nearest exit. Closest first, then most valuable.
     *
     * @param  list<int>  $exitSolarsystemIds
     * @param  list<array{system_id: int, items: int, value: float, ships: list<string>}>  $assetSystems
     * @return list<array{system_id: int, exit_id: int, jumps: int, items: int, value: float, ships: list<string>}>
     */
    public function near(array $exitSolarsystemIds, array $assetSystems, int $maxJumps): array
    {
        $bySystem = [];
        foreach ($assetSystems as $system) {
            $bySystem[(int) $system['system_id']] = $system;
        }

        $hits = [];
        foreach (array_unique($exitSolarsystemIds) as $exitId) {
            foreach ($this->pathfinder->withinJumps([$exitId], [], [], $maxJumps) as $systemId => $jumps) {
                if (! isset($bySystem[$systemId]) || (isset($hits[$systemId]) && $hits[$systemId]['jumps'] <= $jumps)) {
                    continue;
                }

                $hits[$systemId] = [
                    'system_id' => $systemId,
                    'exit_id' => $exitId,
                    'jumps' => $jumps,
                    'items' => (int) $bySystem[$systemId]['items'],
                    'value' => (float) $bySystem[$systemId]['value'],
                    'ships' => $bySystem[$systemId]['ships'],
                ];
            }
        }

        usort($hits, fn (array $a, array $b): int => [$a['jumps'], -$a['value']] <=> [$b['jumps'], -$b['value']]);

        return $hits;
    }

    /**
     * Discord lines: "• **Jita** · 3 jumps from **Hek** · ≈1.2b ISK · Orca, Rorqual".
     *
     * @param  list<array{system_id: int, exit_id: int, jumps: int, items: int, value: float, ships: list<string>}>  $hits
     * @return list<string>
     */
    public function lines(array $hits, int $limit): array
    {
        $shown = array_slice($hits, 0, $limit);
        $names = Solarsystem::query()
            ->whereIn('id', [...array_column($shown, 'system_id'), ...array_column($shown, 'exit_id')])
            ->pluck('name', 'id');

        $lines = array_map(function (array $hit) use ($names): string {
            $where = $hit['jumps'] === 0
                ? sprintf('**%s** (the exit itself)', $names[$hit['system_id']] ?? $hit['system_id'])
                : sprintf('**%s** · %d %s from **%s**', $names[$hit['system_id']] ?? $hit['system_id'], $hit['jumps'], $hit['jumps'] === 1 ? 'jump' : 'jumps', $names[$hit['exit_id']] ?? $hit['exit_id']);
            $ships = $hit['ships'] === [] ? '' : ' · '.implode(', ', array_slice($hit['ships'], 0, 4)).(count($hit['ships']) > 4 ? ' …' : '');

            return sprintf('• %s · ≈%s ISK%s', $where, self::isk($hit['value']), $ships);
        }, $shown);

        if (count($hits) > $limit) {
            $lines[] = sprintf('…and %d more systems.', count($hits) - $limit);
        }

        return $lines;
    }

    public static function isk(float $value): string
    {
        return match (true) {
            $value >= 1e9 => sprintf('%.1fb', $value / 1e9),
            $value >= 1e6 => sprintf('%.0fm', $value / 1e6),
            $value >= 1e3 => sprintf('%.0fk', $value / 1e3),
            default => sprintf('%.0f', $value),
        };
    }
}
