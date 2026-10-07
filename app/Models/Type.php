<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type model representing a type in the game.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $graphic_id
 * @property int $group_id
 * @property int|null $icon_id
 * @property int|null $market_group_id
 * @property int|null $meta_group_id
 * @property int|null $race_id
 * @property bool $published
 * @property float|null $capacity
 * @property float|null $mass
 * @property float|null $base_price
 * @property float|null $volume
 * @property float|null $packaged_volume
 * @property float|null $radius
 * @property int|null $portion_size
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 * @property-read Collection<int,TypeAttribute> $typeAttributes
 * @property-read Race|null $race
 * @property-read Group $group
 * @property-read Icon|null $icon
 * @property-read MarketGroup|null $marketGroup
 * @property-read MetaGroup|null $metaGroup
 * @property-read Graphic|null $graphic
 */
final class Type extends Model
{
    public $incrementing = false;

    /**
     * @return HasMany<TypeAttribute,$this>
     */
    public function typeAttributes(): HasMany
    {
        return $this->hasMany(TypeAttribute::class, 'type_id');
    }

    /**
     * @return BelongsTo<Race,$this>
     */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * @return BelongsTo<Group,$this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Icon,$this>
     */
    public function icon(): BelongsTo
    {
        return $this->belongsTo(Icon::class);
    }

    /**
     * @return BelongsTo<MarketGroup,$this>
     */
    public function marketGroup(): BelongsTo
    {
        return $this->belongsTo(MarketGroup::class);
    }

    /**
     * @return BelongsTo<MetaGroup,$this>
     */
    public function metaGroup(): BelongsTo
    {
        return $this->belongsTo(MetaGroup::class);
    }

    /**
     * @return BelongsTo<Graphic,$this>
     */
    public function graphic(): BelongsTo
    {
        return $this->belongsTo(Graphic::class);
    }

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }

    /** Patch 34: the EVE group of Heavy Interdiction Cruisers. */
    public const int HEAVY_INTERDICTOR_GROUP_ID = 894;

    /** Patch 34: what a Zero-Point Mass Entangler leaves of the hull's mass (−80%). */
    public const float ZERO_POINT_SHARE = 0.2;

    /**
     * The mass a jump in this ship logs. Logged mass must never be more than really went
     * through (the jump log proves reduced / critical / rolled), so HICs and the Odysseus
     * count as if their Zero-Point Mass Entangler were on (20% of the hull). Everything
     * else logs its base hull: plates, prop mods and Higgs only ever add mass.
     */
    public static function loggedJumpMass(?int $typeId): int
    {
        if ($typeId === null) {
            return 0;
        }

        $type = self::query()->whereKey($typeId)->first(['id', 'name', 'group_id', 'mass']);
        if (! $type instanceof self) {
            return 0;
        }

        $mass = (float) ($type->mass ?? 0);
        if ($type->group_id === self::HEAVY_INTERDICTOR_GROUP_ID || $type->name === 'Odysseus') {
            $mass *= self::ZERO_POINT_SHARE;
        }

        return (int) round($mass);
    }
}
