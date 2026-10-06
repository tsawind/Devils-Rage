<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MapRallyPingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patch 30: one rally ping sent to Discord, for the ping log.
 *
 * @property int $id
 * @property int $map_id
 * @property int|null $user_id
 * @property string|null $character_name
 * @property string $kind
 * @property string $title
 * @property string $channel
 * @property string|null $mention
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 * @property-read Map $map
 */
#[UseFactory(MapRallyPingFactory::class)]
final class MapRallyPing extends Model
{
    /** @use HasFactory<MapRallyPingFactory> */
    use HasFactory;

    /**
     * The map this ping was sent from.
     *
     * @return BelongsTo<Map, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }
}
