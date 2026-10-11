<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Patch 37: a viewer link. `map` links show the whole map read only; `route` links (from a
 * mailed route report) show one route as it was when sent. No end time = permanent
 * (managers only). Links that ran out are deleted by the cleanup.
 *
 * @property int $id
 * @property int $map_id
 * @property string $token
 * @property string $kind
 * @property string|null $label
 * @property int|null $created_by_user_id
 * @property string|null $created_by_name
 * @property CarbonImmutable|null $expires_at
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $last_used_at
 * @property int $uses
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Map $map
 */
final class MapShareLink extends Model
{
    /** Members can share for at most this long; managers can also make permanent links. */
    public const int MAX_HOURS = 168;

    /** A route link from a mailed route report. */
    public const int ROUTE_HOURS = 24;

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'payload' => 'array',
        'uses' => 'integer',
    ];

    public static function newToken(): string
    {
        return Str::random(40);
    }

    /**
     * @param  Builder<MapShareLink>  $query
     * @return Builder<MapShareLink>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return BelongsTo<Map, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    public function isLive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * @return array{id: int, kind: string, label: string|null, url: string, created_by_name: string|null, mine: bool, expires_at: string|null, permanent: bool, last_used_at: string|null, uses: int}
     */
    public function toPayload(?User $viewer): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'url' => route($this->kind === 'route' ? 'share.route' : 'maps.share', ['token' => $this->token]),
            'created_by_name' => $this->created_by_name,
            'mine' => $viewer instanceof User && $this->created_by_user_id === $viewer->id,
            'expires_at' => $this->expires_at?->toISOString(),
            'permanent' => $this->expires_at === null,
            'last_used_at' => $this->last_used_at?->toISOString(),
            'uses' => $this->uses,
        ];
    }
}
