<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A character a pilot uses to farm skill points.
 *
 * @property int $character_id
 * @property int $user_id
 * @property string $slot omega | mct | free
 */
class FarmCharacter extends Model
{
    public const SLOTS = [
        'omega' => 'Omega slot (pays the account\'s Omega)',
        'mct' => 'MCT slot (pays a training certificate)',
        'free' => 'Free (Omega is paid anyway)',
    ];

    public $incrementing = false;

    protected $table = 'devilsrage_sp_farm_characters';

    protected $primaryKey = 'character_id';

    protected $fillable = ['character_id', 'user_id', 'slot'];

    /** @var array<string, string> */
    protected $casts = ['character_id' => 'integer', 'user_id' => 'integer'];
}
