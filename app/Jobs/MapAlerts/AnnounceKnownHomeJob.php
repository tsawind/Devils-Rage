<?php

declare(strict_types=1);

namespace App\Jobs\MapAlerts;

use App\Events\Maps\KnownHomeConnectedEvent;
use App\Models\MapSolarsystem;
use App\Services\Discord\ChainWayDescriber;
use App\Services\Discord\DiscordHttp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Patch 43: a system marked as someone's home ("Home of …" in the rename popup) was added
 * to the map or connected to it: everyone on the map gets a notice, and the bot posts a plain
 * message (no @here) in the "devils-pings" channel. Once per system every few hours, and
 * never for the map's own home.
 */
final class AnnounceKnownHomeJob implements ShouldQueue
{
    use Queueable;

    private const int REPEAT_AFTER_SECONDS = 6 * 3600;

    public function __construct(public readonly int $map_solarsystem_id) {}

    public function handle(ChainWayDescriber $wayDescriber, DiscordHttp $http): void
    {
        $placement = MapSolarsystem::query()->with(['map', 'solarsystem', 'details'])->find($this->map_solarsystem_id);
        if (! $placement instanceof MapSolarsystem) {
            return;
        }
        $homeOf = mb_trim((string) $placement->details->home_of);
        if ($homeOf === '') {
            return;
        }
        if ((int) $placement->solarsystem_id === (int) $placement->map->home_solarsystem_id) {
            return;
        }
        if (! Cache::add(sprintf('known-home:%d:%d', $placement->map_id, $placement->solarsystem_id), true, self::REPEAT_AFTER_SECONDS)) {
            return;
        }

        $way = $wayDescriber->fromHome($placement->map, (int) $placement->solarsystem_id);
        $wayLine = $way === null ? null : sprintf('%s · %d %s from %s', $way['line'], $way['jumps'], $way['jumps'] === 1 ? 'jump' : 'jumps', $way['home']);

        event(new KnownHomeConnectedEvent(
            map_id: $placement->map_id,
            map_solarsystem_id: $placement->id,
            solarsystem_id: (int) $placement->solarsystem_id,
            home_of: $homeOf,
            system_name: $placement->solarsystem->name,
            alias: $placement->alias,
            way: $wayLine,
        ));

        $content = sprintf(
            "🏠 **Home of %s** is connected on **%s**: **%s**%s%s",
            $homeOf,
            $placement->map->name,
            $placement->solarsystem->name,
            $placement->alias ? ' ('.$placement->alias.')' : '',
            $wayLine !== null ? "\n↳ ".$wayLine : '',
        );

        foreach ($this->pingChannels($http) as $channelId) {
            try {
                $http->bot()->post('/channels/'.$channelId.'/messages', [
                    'content' => mb_substr($content, 0, 1950),
                    'allowed_mentions' => ['parse' => []],
                ])->throw();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * The "devils-pings" channel (config services.discord.home_pings_channel) in every server
     * the bot is in, looked up by name and remembered for a day.
     *
     * @return list<string>
     */
    private function pingChannels(DiscordHttp $http): array
    {
        $name = (string) config('services.discord.home_pings_channel', 'devils-pings');
        if ($name === '' || ! config('services.discord.bot_token')) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = Cache::remember('discord:home-pings-channels:'.$name, 86400, function () use ($http, $name): array {
            $found = [];
            try {
                foreach ((array) $http->bot()->get('/users/@me/guilds')->throw()->json() as $guild) {
                    foreach ((array) $http->bot()->get('/guilds/'.data_get($guild, 'id').'/channels')->throw()->json() as $channel) {
                        if (data_get($channel, 'name') === $name && (int) data_get($channel, 'type') === 0) {
                            $found[] = (string) data_get($channel, 'id');
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            return $found;
        });

        return $ids;
    }
}
