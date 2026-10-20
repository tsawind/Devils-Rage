<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nickname
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $omega_until
 * @property int|null $omega_reminded
 * @property bool $omega_auto
 * @property int $mct_slots
 * @property \Illuminate\Support\Carbon|null $mct_until
 * @property bool $mct_auto
 * @property int|null $mct_reminded
 * @property int|null $plex_vault
 * @property \Illuminate\Support\Carbon|null $plex_vault_at
 */
class Account extends Model
{
    public const MAX_CHARACTERS = 3;

    protected $table = 'devilsrage_accounts';

    protected $fillable = ['user_id', 'nickname', 'position', 'omega_until', 'omega_reminded', 'omega_auto', 'mct_slots', 'mct_until', 'mct_auto', 'mct_reminded', 'plex_vault', 'plex_vault_at'];

    /** @var array<string, string> */
    protected $casts = [
        'omega_until' => 'date',
        'omega_reminded' => 'integer',
        'omega_auto' => 'boolean',
        'mct_slots' => 'integer',
        'position' => 'integer',
        'mct_until' => 'date',
        'mct_auto' => 'boolean',
        'mct_reminded' => 'integer',
        'plex_vault' => 'integer',
        'plex_vault_at' => 'datetime',
    ];

    /**
     * Patch 49b: the pilot's own order (dragged on Your accounts), then by nickname.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('nickname');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(AccountCharacter::class, 'account_id')->orderBy('position')->orderBy('id');
    }
}
