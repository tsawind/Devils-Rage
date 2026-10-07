<?php

declare(strict_types=1);

namespace App\Actions\MapRageRoll;

use App\Events\Maps\CombatModeTurnedOffEvent;
use App\Models\Map;
use App\Models\MapUserSetting;
use App\Models\User;
use App\Support\Broadcasting\MapBroadcaster;

/**
 * Patch 35: anyone unticks RAGE ROLL. The red mode ends for everyone. When the
 * roll was also a Rage Scanning session, Rage speed goes off for everyone too:
 * the session's speed (it came from the map) and anyone's own speed-only Rage
 * Scanning. Scanners working a coloured rage chain keep theirs.
 */
final readonly class StopRageRollAction
{
    public function __construct(private MapBroadcaster $mapBroadcaster) {}

    /**
     * @return int How many pilots had their own Rage speed turned off.
     */
    public function handle(Map $map, ?User $by = null): int
    {
        if ($map->rage_roll_solarsystem_id === null) {
            return 0;
        }

        $was_scanning = (bool) $map->rage_roll_scanning;

        $map->update([
            'rage_roll_solarsystem_id' => null,
            'rage_roll_started_at' => null,
            'rage_roll_started_by' => null,
            'rage_roll_targets' => null,
            'rage_roll_scanning' => false,
        ]);

        $this->mapBroadcaster->metadataUpdated($map);

        if (! $was_scanning) {
            return 0;
        }

        $settings = MapUserSetting::query()
            ->where('map_id', $map->id)
            ->where('combat_mode', true)
            ->whereNull('combat_color')
            ->get();

        $who = $by->active_character->name ?? 'Someone';
        foreach ($settings as $setting) {
            $setting->update(['combat_mode' => false]);
            if ($by !== null && $by->id === $setting->user_id) {
                continue;
            }
            broadcast(new CombatModeTurnedOffEvent(
                $setting->user_id,
                $map->id,
                'Rage Scanning off: the rage roll is over',
                sprintf('%s ended the rage roll.', $who),
            ));
        }

        return $settings->count();
    }
}
