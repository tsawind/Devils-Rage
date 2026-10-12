<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $character_id
 * @property string|null $launch_group
 */
class AccountCharacter extends Model
{
    /** Launch group key => label. */
    public const GROUPS = ['main' => 'Main', 'second' => 'Second', 'third' => 'Third'];

    protected $table = 'devilsrage_account_characters';

    protected $fillable = ['account_id', 'character_id', 'launch_group'];

    /** @var array<string, string> */
    protected $casts = ['character_id' => 'integer', 'account_id' => 'integer'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function character(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'character_id');
    }
}
