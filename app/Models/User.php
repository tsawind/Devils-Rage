<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\HasApiTokens;

/**
 * User model representing a user in the application.
 *
 * @property int $id
 * @property string $name
 * @property int|null $preferred_character_id
 * @property string|null $display_name Patch 39: a guest's name in the mapper
 * @property Character|null $active_character
 * @property-read Character|null $preferredCharacter
 * @property-read Collection<int,Character> $characters
 * @property-read DiscordAccount|null $discordAccount
 * @property-read Collection<int,MapAlert> $createdMapAlerts
 * @property-read Collection<int,MapUserSetting> $mapUserSettings
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    private const string SESSION_ACTIVE_CHARACTER_ID = 'active_character_id';

    /** Patch 39: this window's main for this request (X-Map-Driver), without touching the session. */
    private ?Character $windowDriver = null;

    public ?Character $active_character = null {
        get {
            if ($this->windowDriver instanceof Character) {
                return $this->windowDriver;
            }

            if ($this->active_character instanceof Character) {
                return $this->active_character;
            }

            // First, check session for active character
            $active_character_id = Session::get(self::SESSION_ACTIVE_CHARACTER_ID);

            // If no session, use the preferred character from database (a freshly created
            // user may not have the column loaded yet: treat it as none).
            $preferred_character_id = $this->getAttributes()['preferred_character_id'] ?? null;
            if (! $active_character_id && $preferred_character_id) {
                $active_character_id = $preferred_character_id;
            }

            $character = $active_character_id
                ? $this->characters->find($active_character_id)
                : null;

            // Preferred/session character no longer exists, clear it and fall back
            if (! $character) {
                if ($preferred_character_id && $preferred_character_id === $active_character_id) {
                    $this->update(['preferred_character_id' => null]);
                }

                Session::forget(self::SESSION_ACTIVE_CHARACTER_ID);

                $character = $this->characters->first();
                if (! $character) {
                    auth()->logout();
                    abort(403, 'No characters found. Please log in again.');
                }
            }

            $this->active_character = $character;

            return $character;
        }
        set {
            $this->windowDriver = null;
            if ($value instanceof Character) {
                Session::put(self::SESSION_ACTIVE_CHARACTER_ID, $value->id);
                $this->active_character = $value;

            } else {
                Session::forget(self::SESSION_ACTIVE_CHARACTER_ID);
                $this->active_character = null;
            }
        }
    }

    /**
     * Patch 39: every browser window or tab has its own main. This request acts as $character
     * (one of the user's own), while the login's session main stays what it was. Null: the login's main.
     */
    public function driveAs(?Character $character): void
    {
        $this->windowDriver = $character;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
    ];

    public function getAuthPassword(): string
    {
        return '';
    }

    public function alertDisplayName(): string
    {
        $preferredCharacterId = $this->hasAttribute('preferred_character_id') ? $this->preferred_character_id : null;
        $character = $this->characters->firstWhere('id', $preferredCharacterId) ?? $this->characters->first();

        if ($character !== null) {
            return $character->name;
        }

        return $this->hasAttribute('name') && $this->name !== '' ? $this->name : 'Unknown user';
    }

    public function getAccessibleIds(): array
    {
        return $this->characters->map(fn (Character $character): array => [
            $character->id,
            $character->corporation_id,
            $character->alliance_id,
        ])
            ->flatten()
            ->whereNotNull()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get the preferred character for the user.
     *
     * @return BelongsTo<Character, $this>
     */
    public function preferredCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'preferred_character_id');
    }

    /**
     * Get the characters associated with the user.
     *
     * @return HasMany<Character, $this>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'user_id');
    }

    /**
     * Patch 46: may change the desktop app's settings for everyone (access.app_admin_character_ids).
     */
    public function isAppAdmin(): bool
    {
        /** @var list<int> $admins */
        $admins = config('access.app_admin_character_ids', []);

        return $admins !== [] && $this->characters()->whereIn('id', $admins)->exists();
    }

    /** @return HasOne<DiscordAccount, $this> */
    public function discordAccount(): HasOne
    {
        return $this->hasOne(DiscordAccount::class);
    }

    /** @return HasMany<MapAlert, $this> */
    public function createdMapAlerts(): HasMany
    {
        return $this->hasMany(MapAlert::class, 'created_by_user_id');
    }

    /**
     * Get the user settings for the map.
     *
     * @return HasMany<MapUserSetting, $this>
     */
    public function mapUserSettings(): HasMany
    {
        return $this->hasMany(MapUserSetting::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
        ];
    }
}
