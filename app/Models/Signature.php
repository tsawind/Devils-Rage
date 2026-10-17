<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LifetimeStatus;
use App\Enums\MassStatus;
use App\Enums\ShipSize;
use App\Support\Actor;
use App\Support\HoleNumbers;
use App\Support\LifeMarks;
use Carbon\CarbonImmutable;
use Database\Factories\SignatureFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signature model representing a signature in the game.
 *
 * @property int $id
 * @property string|null $signature_id
 * @property string|null $alias Locked chain alias of the hole (e.g. "12")
 * @property int|null $armed_by_user_id Patch 13: who armed the hole as their next jump
 * @property string|null $armed_by_name
 * @property CarbonImmutable|null $armed_at
 * @property bool $armed_claimed_alias Arming gave the hole its number (disarming frees it)
 * @property bool $is_static
 * @property bool $is_wandering
 * @property int $map_solarsystem_id
 * @property int|null $map_connection_id
 * @property int|null $wormhole_id
 * @property int|null $signature_type_id
 * @property int|null $signature_category_id
 * @property string|null $raw_type_name
 * @property MassStatus|null $mass_status
 * @property LifetimeStatus $lifetime
 * @property DateTimeImmutable|string|null $lifetime_updated_at
 * @property ShipSize|null $ship_size
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property string|null $created_by_name Patch 37: who pasted or added it
 * @property string|null $type_set_by_name Patch 37: who set its type
 * @property CarbonImmutable|null $type_set_at
 * @property CarbonImmutable|null $eol_since Patch 37: first marked End of life
 * @property CarbonImmutable|null $critical_since Patch 37: first marked Critical
 * @property CarbonImmutable|null $imminent_since Patch 37: turned "closure imminent"
 * @property CarbonImmutable|null $checked_at Patch 37: last looked at in game (status picked or ✓ Checked)
 * @property string|null $checked_by_name
 * @property string|null $checked_note
 * @property-read MapSolarsystem $mapSolarsystem
 * @property-read MapConnection|null $mapConnection
 * @property-read Wormhole|null $wormhole
 * @property-read SignatureType|null $signatureType
 * @property-read SignatureCategory|null $signatureCategory
 */
#[UseFactory(SignatureFactory::class)]
final class Signature extends Model
{
    /** @use HasFactory<SignatureFactory> */
    use HasFactory;

    protected $casts = [
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
        'mass_status' => MassStatus::class,
        'ship_size' => ShipSize::class,
        'lifetime' => LifetimeStatus::class,
        'lifetime_updated_at' => 'immutable_datetime',
        'type_set_at' => 'immutable_datetime',
        'checked_at' => 'immutable_datetime',
        'eol_since' => 'immutable_datetime',
        'critical_since' => 'immutable_datetime',
        'imminent_since' => 'immutable_datetime',
        'is_static' => 'boolean',
        'is_wandering' => 'boolean',
        'armed_at' => 'immutable_datetime',
        'armed_claimed_alias' => 'boolean',
    ];

    /** Arms run out after this many minutes without a jump (patch 13). */
    public const int ARM_MINUTES = 15;

    protected static function booted(): void
    {
        // Jumping a hole (it gets linked to a connection) uses up its arm; the number stays.
        // Patch 37: who added the hole, who set its type, and who last looked at it in game.
        self::creating(function (Signature $signature): void {
            $signature->created_by_name ??= Actor::name();
        });
        self::saving(fn (Signature $signature) => LifeMarks::apply($signature));
        self::saving(function (Signature $signature): void {
            $actor = Actor::name();
            if ($actor === null) {
                return;
            }
            $typed = $signature->isDirty('wormhole_id') || $signature->isDirty('signature_type_id');
            if ($typed && ($signature->getAttributes()['wormhole_id'] ?? $signature->getAttributes()['signature_type_id'] ?? null) !== null) {
                $signature->type_set_by_name = $actor;
                $signature->type_set_at = now()->toImmutable();
            }
            if ($signature->exists && ($signature->isDirty('mass_status') || $signature->isDirty('lifetime'))) {
                $signature->checked_at = now()->toImmutable();
                $signature->checked_by_name = $actor;
                $signature->checked_note = self::checkNote($signature->isDirty('mass_status') ? $signature->getAttributes()['mass_status'] ?? null : null, $signature->isDirty('lifetime') ? $signature->getAttributes()['lifetime'] ?? null : null);
            }
        });
        // Patch 39: a hole number handed out is remembered by its system (numbers only count up).
        self::saved(function (Signature $signature): void {
            $attributes = $signature->getAttributes();
            if (($attributes['alias'] ?? null) !== null && isset($attributes['map_solarsystem_id']) && ($signature->wasRecentlyCreated || $signature->wasChanged('alias'))) {
                HoleNumbers::remember($signature);
            }
        });
        self::saving(function (Signature $signature): void {
            if ($signature->map_connection_id !== null && $signature->armed_by_user_id !== null) {
                $signature->armed_by_user_id = null;
                $signature->armed_by_name = null;
                $signature->armed_at = null;
                $signature->armed_claimed_alias = false;
            }
        });
    }

    /**
     * Patch 37: how someone looked at a hole, for "Last checked … (marked Reduced)".
     */
    public static function checkNote(mixed $massStatus, mixed $lifetime): string
    {
        $mass = $massStatus instanceof MassStatus ? $massStatus->value : (is_string($massStatus) ? $massStatus : null);
        $life = $lifetime instanceof LifetimeStatus ? $lifetime->value : (is_string($lifetime) ? $lifetime : null);

        return match (true) {
            $mass === 'critical' => 'marked Crit',
            $mass === 'reduced' => 'marked Reduced',
            $mass === 'fresh' => 'marked Fresh',
            $life === 'imminent' => 'marked Imminent',
            $life === 'critical' => 'marked EOL!',
            $life === 'eol' => 'marked EOL',
            $life === 'healthy' => 'marked healthy',
            default => 'checked',
        };
    }

    /** Armed by someone, and not run out yet. */
    public function isArmed(): bool
    {
        return $this->armed_by_user_id !== null
            && $this->armed_at !== null
            && $this->armed_at->greaterThan(now()->subMinutes(self::ARM_MINUTES));
    }

    /**
     * The arm as the map shows it, or nulls when not armed.
     *
     * @return array{armed_by_user_id: int|null, armed_by_name: string|null, armed_at: string|null, armed_claimed: bool}
     */
    public function armedPayload(): array
    {
        $armed = $this->isArmed();

        return [
            'armed_by_user_id' => $armed ? $this->armed_by_user_id : null,
            'armed_by_name' => $armed ? $this->armed_by_name : null,
            'armed_at' => $armed ? $this->armed_at?->toISOString() : null,
            // Arming gave it its number: re-arming another hole can take that number along.
            'armed_claimed' => $armed && $this->armed_claimed_alias,
        ];
    }

    /**
     * @return BelongsTo<MapSolarsystem,$this>
     */
    public function mapSolarsystem(): BelongsTo
    {
        return $this->belongsTo(MapSolarsystem::class);
    }

    /**
     * @return BelongsTo<MapConnection,$this>
     */
    public function mapConnection(): BelongsTo
    {
        return $this->belongsTo(MapConnection::class, 'map_connection_id');
    }

    /**
     * Get the wormhole associated with this signature.
     *
     * @return BelongsTo<Wormhole, $this>
     */
    public function wormhole(): BelongsTo
    {
        return $this->belongsTo(Wormhole::class);
    }

    /**
     * Get the signature type associated with this signature.
     *
     * @return BelongsTo<SignatureType, $this>
     */
    public function signatureType(): BelongsTo
    {
        return $this->belongsTo(SignatureType::class);
    }

    /**
     * Get the signature category associated with this signature.
     *
     * @return BelongsTo<SignatureCategory, $this>
     */
    public function signatureCategory(): BelongsTo
    {
        return $this->belongsTo(SignatureCategory::class);
    }
}
