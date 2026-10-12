<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nickname
 * @property \Illuminate\Support\Carbon|null $omega_until
 * @property int|null $omega_reminded
 */
class Account extends Model
{
    public const MAX_CHARACTERS = 3;

    protected $table = 'devilsrage_accounts';

    protected $fillable = ['user_id', 'nickname', 'omega_until', 'omega_reminded'];

    /** @var array<string, string> */
    protected $casts = ['omega_until' => 'date', 'omega_reminded' => 'integer'];

    public function characters(): HasMany
    {
        return $this->hasMany(AccountCharacter::class, 'account_id')->orderBy('position')->orderBy('id');
    }
}
