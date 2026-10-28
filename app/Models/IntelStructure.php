<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Patch 55: a structure or POS someone saw in a system (per map). Changes are logged as
 * IntelEntry rows; removing one only hides it (soft delete), its history stays.
 *
 * @property int $id
 * @property int $map_id
 * @property int $solarsystem_id
 * @property string $kind
 * @property string|null $name
 * @property string|null $owner
 * @property string|null $location
 * @property string $state
 * @property string|null $vulnerable_window
 * @property CarbonImmutable|null $timer_at
 * @property string|null $fit
 * @property string|null $note
 * @property CarbonImmutable $seen_at
 * @property string|null $created_by_name
 * @property string|null $updated_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $updated_at
 */
final class IntelStructure extends Model
{
    use SoftDeletes;

    public const array KINDS = ['astrahus', 'fortizar', 'keepstar', 'raitaru', 'azbel', 'sotiyo', 'athanor', 'tatara', 'pos', 'other'];

    public const array STATES = ['online', 'low_power', 'abandoned', 'anchoring', 'armor_timer', 'hull_timer', 'offline', 'unanchoring', 'gone'];

    /**
     * Patch 58b: the system's structures in short for the Notes panel: timers first, then the
     * latest seen; gone ones left out.
     *
     * @return list<array{id: int, kind: string, name: string|null, owner: string|null, state: string, timer_at: string|null, seen_at: string}>
     */
    public static function briefFor(int $mapId, int $solarsystemId): array
    {
        return self::query()
            ->where('map_id', $mapId)
            ->where('solarsystem_id', $solarsystemId)
            ->where('state', '!=', 'gone')
            ->orderByRaw("CASE WHEN state IN ('armor_timer', 'hull_timer') THEN 0 ELSE 1 END")
            ->orderByDesc('seen_at')
            ->limit(30)
            ->get(['id', 'kind', 'name', 'owner', 'state', 'timer_at', 'seen_at'])
            ->map(fn (self $structure): array => [
                'id' => (int) $structure->id,
                'kind' => $structure->kind,
                'name' => $structure->name,
                'owner' => $structure->owner,
                'state' => $structure->state,
                'timer_at' => $structure->timer_at?->toIso8601String(),
                'seen_at' => $structure->seen_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'timer_at' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
        ];
    }
}
