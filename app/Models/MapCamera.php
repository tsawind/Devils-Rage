<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Patch 62: a camera: part (or all) of a member's EVE window or screen, streamed through the
 * video relay to members and the guest links it allows. "path" is its name on the relay.
 *
 * @property int $id
 * @property int $map_id
 * @property int|null $user_id
 * @property int|null $character_id
 * @property string|null $owner_name the person (SeAT main), stamped when it starts
 * @property string|null $character_name the character whose window it is
 * @property string $name
 * @property string $kind hole / structure / other / pi / screen
 * @property int|null $map_solarsystem_id
 * @property int|null $map_connection_id
 * @property int|null $signature_id Patch 62b: a hole nobody has jumped yet (moves to its pipe once jumped)
 * @property string $path
 * @property string|null $box_key Patch 62n: the share page's box (sharing it again brings this camera back)
 * @property string $quality
 * @property list<int>|null $guest_share_link_ids
 * @property string $status starting / live / paused / ended
 * @property bool $detect watch for wormhole activations (hole cameras)
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Map $map
 * @property-read User|null $user
 * @property-read MapConnection|null $mapConnection
 * @property-read MapSolarsystem|null $mapSolarsystem
 */
final class MapCamera extends Model
{
    /** Patch 62f: 'watch' = a watch area: not streamed, only reports what it sees (and keeps short clips). */
    public const array KINDS = ['hole', 'structure', 'other', 'pi', 'screen', 'watch'];

    /** Patch 62m: 480p2, the lightest (a whole client you only glance at). */
    public const array QUALITIES = ['480p2', '720p5', '1080p10', '1080p30'];

    /** Patch 62m: 'standby' = on, watching and recording at home, but only streams while someone watches. */
    public const array ON_STATUSES = ['starting', 'live', 'paused', 'standby'];

    public static function newPath(): string
    {
        return 'cam-'.Str::lower(Str::random(28));
    }

    /** Live or paused, and checked in recently. */
    public function isOn(): bool
    {
        return in_array($this->status, self::ON_STATUSES, true)
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds((int) config('cameras.stale_seconds')));
    }

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MapConnection, $this>
     */
    public function mapConnection(): BelongsTo
    {
        return $this->belongsTo(MapConnection::class);
    }

    /**
     * @return BelongsTo<MapSolarsystem, $this>
     */
    public function mapSolarsystem(): BelongsTo
    {
        return $this->belongsTo(MapSolarsystem::class);
    }

    /**
     * @return HasMany<MapCameraEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MapCameraEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'guest_share_link_ids' => 'array',
            'detect' => 'boolean',
            'last_seen_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
