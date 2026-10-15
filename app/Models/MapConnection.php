<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\MapAlerts\RecheckCapitalAlertsAction;
use App\Builders\MapConnectionBuilder;
use App\Enums\ConnectionType;
use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Support\Actor;
use App\Support\LifeMarks;
use Carbon\CarbonImmutable;
use Database\Factories\MapConnectionFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a connection between solar systems in a map.
 *
 * @property int $id
 * @property int $map_id
 * @property int $from_map_solarsystem_id
 * @property int $to_map_solarsystem_id
 * @property int|null $wormhole_id
 * @property string|ConnectionType $type
 * @property bool $preserve_mass
 * @property string|MassStatus $mass_status
 * @property string|ShipSize|null $ship_size
 * @property LifetimeStatus $lifetime
 * @property DateTimeImmutable|string|null $lifetime_updated_at
 * @property CarbonImmutable|null $mass_status_updated_at
 * @property CarbonImmutable|null $mass_watched_at
 * @property string|null $mass_watched_by_name
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $eol_since Patch 37: first marked End of life
 * @property CarbonImmutable|null $critical_since Patch 37: first marked Critical
 * @property CarbonImmutable|null $imminent_since Patch 37: turned "closure imminent"
 * @property string|null $checked_by_name Patch 37: who last looked at it in game
 * @property string|null $checked_note
 * @property string|null $mass_status_set_by_name Patch 37: who set the mass status (null: the jump log)
 * @property bool $mass_status_from_log
 * @property bool $should_have_rolled
 * @property bool $from_memory Patch 37: an old chain's pipe that came back with a reconnect (faded)
 * @property CarbonImmutable|null $connected_at
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 * @property-read MapSolarsystem $fromMapSolarsystem
 * @property-read MapSolarsystem $toMapSolarsystem
 * @property-read Map $map
 * @property-read Collection<int, Signature> $signatures
 * @property-read Collection<int, MapConnectionJump> $jumps
 * @property-read int|null $jumps_count
 * @property-read int|string|null $jumps_mass_sum
 * @property-read int|string|null $jumps_mass_since_status
 */
#[UseFactory(MapConnectionFactory::class)]
#[UseEloquentBuilder(MapConnectionBuilder::class)]
final class MapConnection extends Model
{
    /** @use HasFactory<MapConnectionFactory> */
    use HasFactory;

    protected $casts = [
        'connected_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'type' => ConnectionType::class,
        'preserve_mass' => 'boolean',
        'mass_status' => MassStatus::class,
        'ship_size' => ShipSize::class,
        'lifetime' => LifetimeStatus::class,
        'lifetime_updated_at' => 'immutable_datetime',
        'mass_status_updated_at' => 'immutable_datetime',
        'mass_watched_at' => 'immutable_datetime',
        'checked_at' => 'immutable_datetime',
        'eol_since' => 'immutable_datetime',
        'critical_since' => 'immutable_datetime',
        'imminent_since' => 'immutable_datetime',
        'mass_status_from_log' => 'boolean',
        'should_have_rolled' => 'boolean',
        'from_memory' => 'boolean',
    ];

    /**
     * Patch 32: a new mass status starts a new jump log (only jumps since then count).
     */
    protected static function booted(): void
    {
        self::saving(fn (MapConnection $connection) => LifeMarks::apply($connection));
        // XL-only capital alerts: a hole turning XL can open the way to exits further out.
        self::saved(function (MapConnection $connection): void {
            if ($connection->ship_size === ShipSize::ExtraLarge && ($connection->wasRecentlyCreated || $connection->wasChanged('ship_size'))) {
                resolve(RecheckCapitalAlertsAction::class)->handle($connection->map_id);
            }
        });
        // Patch 37: any change by hand (type, mass, life, checked…) proves a remembered pipe is real.
        self::saving(function (MapConnection $connection): void {
            $attributes = $connection->getAttributes();
            if (! $connection->exists || ! (bool) ($attributes['from_memory'] ?? false) || $connection->isDirty('from_memory')) {
                return;
            }
            // The scheduled age check only moves the life status: that is nobody looking at it.
            $automatic = ['updated_at', 'lifetime', 'lifetime_updated_at', 'eol_since', 'critical_since', 'imminent_since'];
            if (array_diff(array_keys($connection->getDirty()), $automatic) !== []) {
                $connection->from_memory = false;
            }
        });
        self::saving(function (MapConnection $connection): void {
            if ($connection->exists && $connection->isDirty('mass_status')) {
                $connection->mass_status_updated_at = now()->toImmutable();
                // Patch 33: set by hand unless the jump log set it.
                if (! $connection->isDirty('mass_status_from_log')) {
                    $connection->mass_status_from_log = false;
                }
                // Patch 37: who set it (the "Reduced by X at 09:20" line); nobody when the log did.
                if (! $connection->isDirty('mass_status_set_by_name')) {
                    $connection->mass_status_set_by_name = $connection->mass_status_from_log ? null : Actor::name();
                }
            }
        });
    }

    /**
     * The solar system this connection is from.
     *
     * @return BelongsTo<MapSolarsystem, $this>
     */
    public function fromMapSolarsystem(): BelongsTo
    {
        return $this->belongsTo(MapSolarsystem::class);
    }

    /**
     * The solar system this connection is to.
     *
     * @return BelongsTo<MapSolarsystem, $this>
     */
    public function toMapSolarsystem(): BelongsTo
    {
        return $this->belongsTo(MapSolarsystem::class);
    }

    /**
     * The map this connection belongs to.
     *
     * @return BelongsTo<Map, $this>
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * The signatures associated with this connection.
     *
     * @return HasMany<Signature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    /**
     * The wormhole associated with this connection.
     *
     * @return BelongsTo<Wormhole, $this>
     */
    public function wormhole(): BelongsTo
    {
        return $this->belongsTo(Wormhole::class);
    }

    /**
     * The ship jumps observed through this connection.
     *
     * @return HasMany<MapConnectionJump, $this>
     */
    public function jumps(): HasMany
    {
        return $this->hasMany(MapConnectionJump::class);
    }
}
