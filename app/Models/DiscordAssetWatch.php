<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DiscordAssetWatchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pilot's "/assets watch" on a map: a DM when a new k-space exit lands within
 * max_jumps gate jumps of a system where they have assets (or ships).
 *
 * @property int $id
 * @property int $user_id
 * @property int $map_id
 * @property int $max_jumps
 * @property bool $ships_only
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Map $map
 */
#[UseFactory(DiscordAssetWatchFactory::class)]
final class DiscordAssetWatch extends Model
{
    /** @use HasFactory<DiscordAssetWatchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Map, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_jumps' => 'integer',
            'ships_only' => 'boolean',
        ];
    }
}
