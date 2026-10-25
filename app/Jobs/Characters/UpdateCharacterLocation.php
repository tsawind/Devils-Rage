<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Actions\MapConnections\RecordMapConnectionJumpAction;
use App\Actions\ShipHistories\UpdateShipHistoryAction;
use App\Actions\Tracking\ReportAppLocationAction;
use App\Models\CharacterStatus;
use App\Support\LocationTiming;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use NicolasKion\Esi\DTO\Location;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Esi;
use Throwable;

final class UpdateCharacterLocation implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $character_status_id)
    {
        //
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     * @throws ConnectionException
     */
    public function handle(Esi $esi, UpdateShipHistoryAction $action, RecordMapConnectionJumpAction $recordJumpAction): void
    {
        $characterStatus = CharacterStatus::query()->find($this->character_status_id);

        if ($characterStatus === null) {
            return;
        }

        $location_request = $esi->getLocation($characterStatus->character);

        if ($location_request->failed()) {
            Log::info(sprintf('Failed to fetch location for character %d', $characterStatus->character_id), (array) $location_request);

            return;
        }

        $location = $location_request->data;

        // Patch 54: a new system is told to everyone's map now, before the ship lookup and
        // without waiting for the rest of the round (every online character's ESI calls).
        $previous_solarsystem_id = $characterStatus->solarsystem_id;
        $appTrusted = $characterStatus->app_location_at?->gt(now()->subSeconds(ReportAppLocationAction::TRUSTED_FOR_SECONDS)) ?? false;
        if ($location->solar_system_id !== $characterStatus->solarsystem_id && ! $appTrusted) {
            $characterStatus->update([
                'solarsystem_id' => $location->solar_system_id,
                'station_id' => $location->station_id,
                'structure_id' => $location->structure_id,
            ]);
            DispatchCharacterStatusEvents::broadcastFor([$characterStatus->character_id]);
            LocationTiming::moved($characterStatus->character_id, $this->batch()?->createdAt);
        }

        $ship_request = $esi->getShip($characterStatus->character);

        if ($ship_request->failed()) {
            Log::info(sprintf('Failed to fetch ship for character %d', $characterStatus->character_id), (array) $ship_request);
            // Moved above already: the jump is still logged (with the ship it had).
            if ($previous_solarsystem_id !== $location->solar_system_id) {
                try {
                    $recordJumpAction->handle($characterStatus->character_id, $previous_solarsystem_id, $location->solar_system_id, $characterStatus->ship_type_id, $characterStatus->ship_name);
                } catch (Throwable $exception) {
                    Log::warning(sprintf('Failed to record connection jump for character %d', $characterStatus->character_id), ['exception' => $exception]);
                }
            }

            return;
        }

        $ship = $ship_request->data;

        // Patch 46: the desktop app just saw a jump in the chat log; ESI can still show the
        // old system for a few seconds. Don't move the character back meanwhile.
        $appSaysOtherwise = $characterStatus->app_location_at?->gt(now()->subSeconds(ReportAppLocationAction::TRUSTED_FOR_SECONDS))
            && $location->solar_system_id !== $characterStatus->solarsystem_id;
        if ($appSaysOtherwise) {
            return;
        }

        $characterStatus->update([
            'solarsystem_id' => $location->solar_system_id,
            'station_id' => $location->station_id,
            'structure_id' => $location->structure_id,
            'ship_name' => $ship->ship_name,
            'ship_type_id' => $ship->ship_type_id,
            'ship_item_id' => $ship->ship_item_id,
        ]);

        $action->handle(
            $characterStatus->character_id,
            $ship->ship_item_id,
            $ship->ship_type_id,
            $ship->ship_name
        );

        if ($characterStatus->wasChanged()) {
            // Mark that event should be dispatched
            $characterStatus->update(['event_queued_at' => now()]);
        }

        $this->recordJump($recordJumpAction, $characterStatus, $previous_solarsystem_id, $location, $ship);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->character_status_id)->dontRelease()->expireAfter(60)];
    }

    /**
     * A jump-log failure must never break location polling.
     */
    private function recordJump(
        RecordMapConnectionJumpAction $recordJumpAction,
        CharacterStatus $characterStatus,
        ?int $previous_solarsystem_id,
        Location $location,
        Ship $ship,
    ): void {
        if ($previous_solarsystem_id === null || $previous_solarsystem_id === $location->solar_system_id) {
            return;
        }

        try {
            $recordJumpAction->handle(
                $characterStatus->character_id,
                $previous_solarsystem_id,
                $location->solar_system_id,
                $ship->ship_type_id,
                $ship->ship_name,
            );
        } catch (Throwable $exception) {
            Log::warning(sprintf('Failed to record connection jump for character %d', $characterStatus->character_id), [
                'exception' => $exception,
            ]);
        }
    }
}
