<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Models\DiscordAccount;
use App\Models\DiscordAssetWatch;
use App\Models\Map;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Support\Facades\Gate;

/**
 * "/assets watch" and "/assets stop".
 */
final readonly class ManageAssetWatchAction
{
    public function __construct(private SeatClient $seat) {}

    public function watch(DiscordAccount $account, int $mapId, int $maxJumps, bool $shipsOnly): string
    {
        $map = Map::query()->find($mapId);
        if ($map === null || Gate::forUser($account->user)->denies('view', $map)) {
            return 'That map is unavailable.';
        }

        try {
            if ($this->seat->assetSystems($account->discord_user_id, $shipsOnly) === null) {
                return SeatMessages::NOT_LINKED;
            }
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        DiscordAssetWatch::query()->updateOrCreate(
            ['user_id' => $account->user_id, 'map_id' => $map->id],
            ['max_jumps' => $maxJumps, 'ships_only' => $shipsOnly],
        );

        return sprintf(
            'Watching **%s**: I\'ll DM you when a new k-space exit lands within %d jumps of your %s. Stop with `/assets stop`.',
            $map->name,
            $maxJumps,
            $shipsOnly ? 'ships' : 'stuff',
        );
    }

    public function stop(DiscordAccount $account, ?int $mapId): string
    {
        $deleted = DiscordAssetWatch::query()
            ->where('user_id', $account->user_id)
            ->when($mapId !== null, fn ($query) => $query->where('map_id', $mapId))
            ->delete();

        return $deleted === 0 ? 'You had no asset watch there.' : sprintf('Stopped %d asset %s.', $deleted, $deleted === 1 ? 'watch' : 'watches');
    }
}
