<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patch 37: a pilot's hull with its real jump masses: cold (prop off) and hot (prop on).
 * From the fit SeAT knows (source "seat") or typed in (source "manual", never overwritten
 * by SeAT). Shared with the corp.
 *
 * @property int $id
 * @property int $character_id
 * @property int $ship_type_id
 * @property int $cold_mass
 * @property int $hot_mass
 * @property bool $higgs
 * @property int $plates
 * @property int $entanglers
 * @property list<array{slot: string, name: string}>|null $modules
 * @property list<array{key: string, label: string, mode: string, mass: int}>|null $options
 * @property int $prop_mass
 * @property string $source
 * @property string|null $note
 * @property string|null $updated_by_name
 * @property CarbonImmutable|null $fitted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Type|null $shipType
 */
final class ShipProfile extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Type, $this>
     */
    public function shipType(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'ship_type_id');
    }

    /**
     * The mass a jump in this ship logs: hot or cold. A logged jump must never weigh more than
     * what really went through, so with several options (a HIC's entanglers, a carrier's two
     * props) the lightest of that kind is logged.
     */
    public function jumpMass(bool $hot): int
    {
        $masses = array_column(array_filter($this->options ?? [], fn (array $option): bool => $option['mode'] === ($hot ? 'hot' : 'cold')), 'mass');
        if ($masses !== []) {
            return (int) min($masses);
        }

        return $hot ? $this->hot_mass : $this->cold_mass;
    }

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'ship_type_id' => 'integer',
            'cold_mass' => 'integer',
            'hot_mass' => 'integer',
            'higgs' => 'boolean',
            'plates' => 'integer',
            'entanglers' => 'integer',
            'modules' => 'array',
            'options' => 'array',
            'prop_mass' => 'integer',
            'fitted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
