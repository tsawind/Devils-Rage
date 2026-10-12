<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Enums\JumpShipType;
use App\Enums\SolarsystemClass;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Services\JumpRange\JumpRangeCalculator;
use App\Services\Routing\CapitalPath;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Support\Facades\Gate;

/**
 * "/caps": which of the pilot's jump-drive ships (parked or flown, from SeAT) can jump
 * to a k-space exit of the chain right now, and whether every hole from home is XL.
 */
final readonly class ListCapitalReachAction
{
    private const int MAX_SHIPS = 12;

    public function __construct(
        private SeatClient $seat,
        private JumpRangeCalculator $calculator,
        private CapitalPath $capitalPath,
    ) {}

    public function handle(DiscordAccount $account, int $mapId): string
    {
        $map = Map::query()->find($mapId);
        if ($map === null || Gate::forUser($account->user)->denies('view', $map)) {
            return 'That map is unavailable.';
        }

        try {
            $ships = $this->seat->jumpShips($account->discord_user_id);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        if ($ships === null) {
            return SeatMessages::NOT_LINKED;
        }

        if ($ships === []) {
            return 'SeAT shows no jump-drive ships on your characters.';
        }

        $exits = Solarsystem::query()
            ->whereIn('id', $map->mapSolarsystems()->pluck('solarsystem_id'))
            ->where('type', 'eve')
            ->orderBy('name')
            ->get();
        if ($exits->isEmpty()) {
            return sprintf('No k-space exits on **%s** right now.', $map->name);
        }

        $xlReachable = $this->capitalPath->reachableFromHome($map) ?? [];
        $origins = Solarsystem::query()->whereIn('id', array_column($ships, 'system_id'))->get()->keyBy('id');

        $lines = [];
        foreach (array_slice($ships, 0, self::MAX_SHIPS) as $ship) {
            $type = JumpShipType::tryFrom($ship['jump_class']);
            $origin = $origins->get($ship['system_id']);
            if (! $type instanceof JumpShipType || ! $origin instanceof Solarsystem) {
                continue;
            }

            $range = $type->maxRangeLy($ship['jdc']);
            $reach = [];
            foreach ($exits as $exit) {
                if (! $type->canEnterHighsec() && SolarsystemClass::fromSecurity((float) $exit->security) === SolarsystemClass::H) {
                    continue;
                }

                $distance = $this->calculator->distanceLy($origin, $exit);
                if ($distance <= $range) {
                    $reach[] = sprintf('**%s** %.1f ly%s', $exit->name, $distance, isset($xlReachable[$exit->id]) ? ' (XL all the way)' : ' (not all XL)');
                }
            }

            $lines[] = sprintf(
                '• **%s** · %s · JDC %d · in %s → %s',
                $ship['ship'],
                $ship['character'],
                $ship['jdc'],
                $origin->name,
                $reach === [] ? sprintf('no exit within %.1f ly', $range) : implode(', ', $reach),
            );
        }

        if (count($ships) > self::MAX_SHIPS) {
            $lines[] = sprintf('…and %d more ships.', count($ships) - self::MAX_SHIPS);
        }

        return implode("\n", [sprintf('**Jump ships vs exits on %s**', $map->name), ...$lines]);
    }
}
