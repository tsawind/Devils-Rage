<?php

declare(strict_types=1);

namespace App\Actions\MapConnections;

use App\Enums\ConnectionType;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MapSolarsystem;
use App\Models\MapUserSetting;
use App\Models\ShipProfile;
use App\Models\Solarsystem;
use App\Support\MassSessions;
use App\Models\Type;
use App\Utilities\StargatePairDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Records a tracked character's jump between two solar systems against the
 * wormhole connection linking them, on every map that tracks the character.
 *
 * When the connection does not exist yet (the client creates it moments after
 * the location poll observes the jump), a pending row is stored instead and
 * claimed by ClaimPendingConnectionJumpsAction at connection creation.
 */
final readonly class RecordMapConnectionJumpAction
{
    public function __construct(
        private BroadcastMapConnectionAction $broadcastMapConnection,
        private InferMassStatusFromJumpsAction $inferMassStatus,
        private StargatePairDetector $stargatePairDetector,
    ) {}

    public function handle(
        int $character_id,
        int $from_solarsystem_id,
        int $to_solarsystem_id,
        ?int $ship_type_id,
        ?string $ship_name,
    ): void {
        $from_solarsystem = Solarsystem::query()->find($from_solarsystem_id);
        $to_solarsystem = Solarsystem::query()->find($to_solarsystem_id);

        if ($from_solarsystem === null || $to_solarsystem === null) {
            return;
        }

        /* A gate pair means the character travelled through a stargate, not a
         * wormhole — even a mapped wormhole connection between the same pair
         * would make the attribution ambiguous, so we record nothing.
         */
        if ($this->stargatePairDetector->isStargatePair($from_solarsystem, $to_solarsystem)) {
            return;
        }

        $maps = $this->getMapsTrackingCharacter($character_id);

        if ($maps->isEmpty()) {
            return;
        }

        // Patch 34: HICs / Odysseus log as if their Zero-Point Mass Entangler were on.
        // Patch 37: a ship profile (its real fit) logs its cold or hot mass, by the pilot's JUMPING HOT switch.
        $user_id = Character::query()->whereKey($character_id)->value('user_id');
        $profile = $ship_type_id === null ? null : ShipProfile::query()->where('character_id', $character_id)->where('ship_type_id', $ship_type_id)->first();

        foreach ($maps as $map) {
            $setting = $user_id === null ? null : MapUserSetting::query()->where('map_id', $map->id)->where('user_id', $user_id)->first(['jumping_hot', 'jumping_thread']);
            $hot = $setting instanceof MapUserSetting && $setting->jumping_hot;
            $thread = $setting instanceof MapUserSetting && $setting->jumping_thread;
            $mass = $profile instanceof ShipProfile ? $profile->jumpMass($hot, $thread) : Type::loggedJumpMass($ship_type_id);
            $this->recordJumpOnMap($map, $character_id, $from_solarsystem_id, $to_solarsystem_id, $ship_type_id, $ship_name, $mass, $hot, $thread);
        }
    }

    private function recordJumpOnMap(
        Map $map,
        int $character_id,
        int $from_solarsystem_id,
        int $to_solarsystem_id,
        ?int $ship_type_id,
        ?string $ship_name,
        int $mass,
        bool $hot = false,
        bool $thread = false,
    ): void {
        $connection = $this->findConnection($map->id, $from_solarsystem_id, $to_solarsystem_id);

        if ($connection instanceof MapConnection && $connection->type !== ConnectionType::Wormhole) {
            return;
        }

        if (! $connection instanceof MapConnection && ! $this->originIsOnMap($map->id, $from_solarsystem_id)) {
            return;
        }

        // Patch 37: a running mass plan: this jump logs the plan's mass for this pilot's step.
        if ($connection instanceof MapConnection) {
            $planned = MassSessions::followPlan($connection, $character_id, $from_solarsystem_id);
            if ($planned !== null && $planned > 0) {
                $mass = $planned;
            }
        }

        $jump = MapConnectionJump::query()->create([
            'map_id' => $map->id,
            'map_connection_id' => $connection?->id,
            'character_id' => $character_id,
            'from_solarsystem_id' => $from_solarsystem_id,
            'to_solarsystem_id' => $to_solarsystem_id,
            'ship_type_id' => $ship_type_id,
            'ship_name' => $ship_name,
            'mass' => $mass,
            'is_hot' => $hot,
            'is_thread' => $thread,
        ]);

        /* Pending row: the connection may have been created between our first
         * lookup and the insert (the claim at creation time would then have
         * missed this row), so check once more and self-claim.
         */
        if (! $connection instanceof MapConnection) {
            $connection = $this->findConnection($map->id, $from_solarsystem_id, $to_solarsystem_id);

            if (! $connection instanceof MapConnection || $connection->type !== ConnectionType::Wormhole) {
                return;
            }

            $jump->update(['map_connection_id' => $connection->id]);
        }

        // Patch 37: a jump proves a remembered (faded) pipe is real.
        MapConnection::query()->whereKey($connection->id)->where('from_memory', true)->update(['from_memory' => false]);

        // Patch 33: the log may prove the hole reduced / critical / rolled.
        $this->inferMassStatus->handle($connection);
        $this->broadcastMapConnection->handle($connection);
    }

    /**
     * @return Collection<int, Map>
     */
    private function getMapsTrackingCharacter(int $character_id): Collection
    {
        return Map::query()
            ->whereHas('mapUserSettings', fn (Builder $query) => $query
                ->where('tracking_allowed', true)
                ->where('is_tracking', true)
                ->whereHas('user.characters', fn (Builder $query) => $query->where('id', $character_id))
            )
            ->get();
    }

    private function findConnection(int $map_id, int $from_solarsystem_id, int $to_solarsystem_id): ?MapConnection
    {
        return MapConnection::query()
            ->connectsSolarsystemsInMap($map_id, $from_solarsystem_id, $to_solarsystem_id)
            ->first();
    }

    private function originIsOnMap(int $map_id, int $solarsystem_id): bool
    {
        return MapSolarsystem::query()
            ->where('map_id', $map_id)
            ->where('solarsystem_id', $solarsystem_id)
            ->whereNotNull('position_x')
            ->exists();
    }
}
