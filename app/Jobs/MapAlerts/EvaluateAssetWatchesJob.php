<?php

declare(strict_types=1);

namespace App\Jobs\MapAlerts;

use App\Models\DiscordAssetWatch;
use App\Models\DiscordAssetWatchDelivery;
use App\Models\MapSolarsystem;
use App\Services\Discord\DiscordHttp;
use App\Services\Seat\AssetProximity;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * "/assets watch": when a k-space system lands on a map, DM every watcher whose assets
 * (from SeAT) sit within their chosen gate jumps of it. One DM per watch per placement.
 */
final class EvaluateAssetWatchesJob implements ShouldQueue
{
    use Queueable;

    private const int LINES = 10;

    public function __construct(public readonly int $map_solarsystem_id) {}

    public function handle(SeatClient $seat, AssetProximity $proximity, DiscordHttp $http): void
    {
        if (! $seat->configured()) {
            return;
        }

        $placement = MapSolarsystem::query()->with(['map', 'solarsystem'])->find($this->map_solarsystem_id);
        if ($placement === null || $placement->solarsystem->type !== 'eve') {
            return;
        }

        $watches = DiscordAssetWatch::query()
            ->where('map_id', $placement->map_id)
            ->with(['user.discordAccount', 'user.characters'])
            ->get();

        foreach ($watches as $watch) {
            $this->evaluate($watch, $placement, $seat, $proximity, $http);
        }
    }

    private function evaluate(DiscordAssetWatch $watch, MapSolarsystem $placement, SeatClient $seat, AssetProximity $proximity, DiscordHttp $http): void
    {
        $discordUserId = $watch->user->discordAccount?->discord_user_id;
        if (! is_string($discordUserId) || $discordUserId === '' || Gate::forUser($watch->user)->denies('view', $placement->map)) {
            return;
        }

        try {
            $systems = $seat->assetSystems($discordUserId, $watch->ships_only);
        } catch (SeatUnavailableException $exception) {
            report($exception);

            return;
        }

        $hits = $systems === null ? [] : $proximity->near([$placement->solarsystem_id], $systems, $watch->max_jumps);
        if ($hits === []) {
            return;
        }

        $delivery = DiscordAssetWatchDelivery::query()->createOrFirst([
            'discord_asset_watch_id' => $watch->id,
            'map_solarsystem_id' => $placement->id,
        ]);
        if (! $delivery->wasRecentlyCreated) {
            return;
        }

        $content = implode("\n", [
            sprintf('📦 New exit **%s** on **%s** is near your %s:', $placement->solarsystem->name, $placement->map->name, $watch->ships_only ? 'ships' : 'stuff'),
            ...$proximity->lines($hits, self::LINES),
            '-# Stop these with `/assets stop`.',
        ]);

        try {
            $channelId = Cache::rememberForever(
                'discord:dm-channel:'.$discordUserId,
                fn (): ?string => $http->bot()->post('/users/@me/channels', ['recipient_id' => $discordUserId])->throw()->json('id'),
            );
            $http->bot()->post('/channels/'.$channelId.'/messages', [
                'content' => mb_substr($content, 0, 1950),
                'allowed_mentions' => ['parse' => []],
            ])->throw();
        } catch (Throwable $exception) {
            // Not sent: free the slot so a later run of this placement may try again.
            $delivery->delete();
            report($exception);
        }
    }
}
