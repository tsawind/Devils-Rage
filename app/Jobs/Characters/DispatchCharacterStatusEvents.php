<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Events\Characters\CharacterStatusUpdatedEvent;
use App\Events\Characters\UserCharacterStatusUpdatedEvent;
use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\Map;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchCharacterStatusEvents implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all characters where events need to be dispatched
        $updated_character_ids = CharacterStatus::query()
            ->whereNotNull('event_queued_at')
            ->pluck('character_id');

        if ($updated_character_ids->isEmpty()) {
            return;
        }

        // Reset the event_queued_at for processed characters
        CharacterStatus::query()
            ->whereNotNull('event_queued_at')
            ->update(['event_queued_at' => null]);

        self::broadcastFor($updated_character_ids->all());
    }

    /**
     * Tells every map tracking these characters, and their owners, that they changed. Patch 54:
     * also called straight away for a jump the desktop app reported, without waiting for ESI.
     *
     * @param  array<int, int>  $character_ids
     */
    public static function broadcastFor(array $character_ids): void
    {
        Map::query()
            ->whereHas('mapUserSettings', fn (Builder $query) => $query
                ->where('tracking_allowed', true)
                ->whereHas('user.characters', fn (Builder $query) => $query->whereIn('id', $character_ids))
            )
            ->select('maps.id')
            ->lazy()
            ->each(fn (Map $map) => CharacterStatusUpdatedEvent::dispatch($map->id));

        // Notify each owning user directly so their character list refreshes even
        // for characters that are not on any map they are currently viewing.
        Character::query()
            ->whereIn('id', $character_ids)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->each(fn (int $userId) => UserCharacterStatusUpdatedEvent::dispatch($userId));
    }
}
