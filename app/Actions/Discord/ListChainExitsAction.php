<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Services\Discord\ChainExitDescriber;
use Illuminate\Support\Facades\Gate;

/**
 * "/exits now": the map's k-space exits with security, region and nearest trade hub.
 */
final readonly class ListChainExitsAction
{
    private const int EXITS = 15;

    public function __construct(private ChainExitDescriber $describer) {}

    public function handle(DiscordAccount $account, int $mapId): string
    {
        $map = Map::query()->find($mapId);
        if ($map === null || Gate::forUser($account->user)->denies('view', $map)) {
            return 'That map is unavailable.';
        }

        $exits = Solarsystem::query()
            ->whereIn('id', $map->mapSolarsystems()->pluck('solarsystem_id'))
            ->where('type', 'eve')
            ->with('region')
            ->orderByDesc('security')
            ->get();
        if ($exits->isEmpty()) {
            return sprintf('No k-space exits on **%s** right now.', $map->name);
        }

        $lines = [sprintf('🚪 **Exits on %s** (%d)', $map->name, $exits->count())];
        foreach ($exits->take(self::EXITS) as $exit) {
            // Patch 39: the way from home too, while the reply stays inside Discord's 2000 characters.
            $full = '• '.$this->describer->lineWithWay($map, $exit);
            $lines[] = mb_strlen(implode("\n", [...$lines, $full])) < 1800 ? $full : '• '.$this->describer->line($exit);
        }

        if ($exits->count() > self::EXITS) {
            $lines[] = sprintf('…and %d more.', $exits->count() - self::EXITS);
        }

        return implode("\n", $lines);
    }
}
