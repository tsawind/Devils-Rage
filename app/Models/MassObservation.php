<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 37: a status someone saw on a hole, with the mass logged through it at that moment;
 * flip_mass = the jump that made it this status ("crit, made by DeltaMechanic's 32.4m").
 *
 * @property int $id
 * @property int $map_connection_id
 * @property string $status fresh | reduced | critical
 * @property int $logged_mass
 * @property int|null $flip_mass
 * @property string|null $flip_by
 * @property string|null $by_name
 * @property CarbonImmutable|null $created_at
 */
final class MassObservation extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_mass' => 'integer',
            'flip_mass' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** Everything logged through the hole so far. */
    public static function loggedMass(int $mapConnectionId): int
    {
        return (int) MapConnectionJump::query()->where('map_connection_id', $mapConnectionId)->sum('mass');
    }

    /** Note what someone saw (a mark or ✓ Checked in game) with the mass logged at that moment. */
    public static function note(int $mapConnectionId, string $status, ?string $byName): self
    {
        return self::query()->create([
            'map_connection_id' => $mapConnectionId,
            'status' => $status,
            'logged_mass' => self::loggedMass($mapConnectionId),
            'by_name' => $byName,
        ]);
    }
}
