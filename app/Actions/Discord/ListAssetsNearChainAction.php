<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Services\Seat\AssetProximity;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Support\Facades\Gate;

/**
 * "/assets near": the pilot's asset systems (from SeAT) within a few gate jumps of
 * the chain's k-space exits.
 */
final readonly class ListAssetsNearChainAction
{
    private const int LINES = 15;

    public function __construct(
        private SeatClient $seat,
        private AssetProximity $proximity,
    ) {}

    public function handle(DiscordAccount $account, int $mapId, int $maxJumps, bool $shipsOnly): string
    {
        $map = Map::query()->find($mapId);
        if ($map === null || Gate::forUser($account->user)->denies('view', $map)) {
            return 'That map is unavailable.';
        }

        $exitIds = Solarsystem::query()
            ->whereIn('id', $map->mapSolarsystems()->pluck('solarsystem_id'))
            ->where('type', 'eve')
            ->pluck('id')
            ->all();
        if ($exitIds === []) {
            return sprintf('No k-space exits on **%s** right now.', $map->name);
        }

        try {
            $systems = $this->seat->assetSystems($account->discord_user_id, $shipsOnly);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        if ($systems === null) {
            return SeatMessages::NOT_LINKED;
        }

        $hits = $this->proximity->near($exitIds, $systems, $maxJumps);
        if ($hits === []) {
            return sprintf('None of your %s is within %d jumps of an exit on **%s**.', $shipsOnly ? 'ships' : 'stuff', $maxJumps, $map->name);
        }

        return implode("\n", [
            sprintf('**Your %s within %d jumps of %s exits**', $shipsOnly ? 'ships' : 'stuff', $maxJumps, $map->name),
            ...$this->proximity->lines($hits, self::LINES),
        ]);
    }
}
