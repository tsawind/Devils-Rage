<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MassSessions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patch 37: "⚖ Adding mass…" on a hole. Everyone on the map sees it.
 *
 * @property int $id
 * @property int $map_id
 * @property int $map_connection_id
 * @property int|null $nominal_mass Patch 52: the hole type's size, for the kept range
 * @property int|null $user_id
 * @property string|null $started_by_name
 * @property string $goal watch | reduced | crit | roll | closer
 * @property list<int>|null $crew_ids
 * @property bool $only_our_jumps
 * @property bool $hurry
 * @property int|null $home_map_solarsystem_id the side the crew starts from
 * @property array{steps: list<array<string, mixed>>}|null $plan
 * @property CarbonImmutable|null $plan_started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read MapConnection $mapConnection
 */
final class MassSession extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::saved(function (): void {
            MassSessions::forget();
        });
        self::deleted(function (): void {
            MassSessions::forget();
        });
    }

    /**
     * @return BelongsTo<MapConnection, $this>
     */
    public function mapConnection(): BelongsTo
    {
        return $this->belongsTo(MapConnection::class);
    }

    protected function casts(): array
    {
        return [
            'crew_ids' => 'array',
            'only_our_jumps' => 'boolean',
            'hurry' => 'boolean',
            'plan' => 'array',
            'plan_started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
