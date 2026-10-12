<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscordFeedKind;
use Carbon\CarbonImmutable;
use Database\Factories\DiscordChannelFeedFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A channel the bot posts into by itself: new chain exits of a map, or the daily
 * structure fuel summary (fuel comes from SeAT as the creator, so it needs a leader).
 *
 * @property int $id
 * @property DiscordFeedKind $kind
 * @property int|null $map_id
 * @property string $discord_guild_id
 * @property string $discord_channel_id
 * @property int $created_by_user_id
 * @property CarbonImmutable|null $last_posted_at
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 * @property-read Map|null $map
 * @property-read User $creator
 */
#[UseFactory(DiscordChannelFeedFactory::class)]
final class DiscordChannelFeed extends Model
{
    /** @use HasFactory<DiscordChannelFeedFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Map, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DiscordFeedKind::class,
            'last_posted_at' => 'immutable_datetime',
        ];
    }
}
