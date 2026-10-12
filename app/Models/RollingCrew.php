<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 37: a person's rolling crew: characters in their order (ISBoxer order), some
 * expendable (may be sent on a gamble when hurried), and whether pilots may swap ships
 * (juggling past the 5-minute polarization).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $owner_name
 * @property string $name
 * @property list<array{character_id: int, name: string, expendable?: bool}> $members
 * @property bool $can_swap
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RollingCrew extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'members' => 'array',
            'can_swap' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
