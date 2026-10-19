<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Actions\MapConnections\RecordMapConnectionJumpAction;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\Solarsystem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Patch 46: the desktop app read "Channel changed to Local : <system>" from EVE's chat log.
 * The character is there now: move it on the map straight away (the same way an ESI answer
 * does), without waiting for the next location poll.
 */
final readonly class ReportAppLocationAction
{
    /** How long an app report outranks ESI answers that still show the old system. */
    public const int TRUSTED_FOR_SECONDS = 20;

    public function __construct(private RecordMapConnectionJumpAction $recordJumpAction) {}

    /**
     * @return bool whether the character moved
     */
    public function handle(Character $character, Solarsystem $solarsystem): bool
    {
        $status = CharacterStatus::query()->where('character_id', $character->id)->first();

        if ($status === null) {
            return false;
        }

        $previous_solarsystem_id = $status->solarsystem_id;

        if ($previous_solarsystem_id === $solarsystem->id) {
            $status->update(['app_location_at' => now()]);

            return false;
        }

        $status->update([
            'solarsystem_id' => $solarsystem->id,
            'station_id' => null,
            'structure_id' => null,
            'app_location_at' => now(),
            'event_queued_at' => now(),
        ]);

        if ($previous_solarsystem_id !== null) {
            try {
                $this->recordJumpAction->handle(
                    $character->id,
                    $previous_solarsystem_id,
                    $solarsystem->id,
                    $status->ship_type_id,
                    $status->ship_name,
                );
            } catch (Throwable $exception) {
                Log::warning(sprintf('Failed to record app-reported jump for character %d', $character->id), [
                    'exception' => $exception,
                ]);
            }
        }

        return true;
    }
}
