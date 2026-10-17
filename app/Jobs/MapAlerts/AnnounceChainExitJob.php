<?php

declare(strict_types=1);

namespace App\Jobs\MapAlerts;

use App\Enums\DiscordFeedKind;
use App\Models\DiscordChannelFeed;
use App\Models\MapSolarsystem;
use App\Services\Discord\ChainExitDescriber;
use App\Services\Discord\DiscordHttp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * "/exits here": post a new k-space exit of a map into every channel following that map.
 * One post per channel per exit for a few hours, even when the system is re-added.
 */
final class AnnounceChainExitJob implements ShouldQueue
{
    use Queueable;

    private const int REPEAT_AFTER_SECONDS = 6 * 3600;

    public function __construct(public readonly int $map_solarsystem_id) {}

    public function handle(ChainExitDescriber $describer, DiscordHttp $http): void
    {
        $placement = MapSolarsystem::query()->with(['map', 'solarsystem.region'])->find($this->map_solarsystem_id);
        if ($placement === null || $placement->solarsystem->type !== 'eve') {
            return;
        }

        $feeds = DiscordChannelFeed::query()
            ->where('kind', DiscordFeedKind::ChainExits)
            ->where('map_id', $placement->map_id)
            ->with('creator')
            ->get();
        if ($feeds->isEmpty()) {
            return;
        }

        $content = sprintf('🚪 New exit on **%s**: %s', $placement->map->name, $describer->lineWithWay($placement->map, $placement->solarsystem));

        foreach ($feeds as $feed) {
            if (Gate::forUser($feed->creator)->denies('view', $placement->map)) {
                continue;
            }

            $key = sprintf('discord:exit-feed:%d:%d', $feed->id, $placement->solarsystem_id);
            if (! Cache::add($key, true, self::REPEAT_AFTER_SECONDS)) {
                continue;
            }

            try {
                $http->bot()->post('/channels/'.$feed->discord_channel_id.'/messages', [
                    'content' => mb_substr($content, 0, 1950),
                    'allowed_mentions' => ['parse' => []],
                ])->throw();
                $feed->update(['last_posted_at' => now()]);
            } catch (Throwable $exception) {
                Cache::forget($key);
                report($exception);
            }
        }
    }
}
