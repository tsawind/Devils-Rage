<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property bool $share
 * @property string|null $person_name Patch 39: the name they go by ("Tsa"); empty = their main's
 * @property string $discord_style Patch 39: main / name / both
 */
class AccountSetting extends Model
{
    public $incrementing = false;

    protected $table = 'devilsrage_account_settings';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'share', 'person_name', 'discord_style'];

    /** @var array<string, string> */
    protected $casts = ['share' => 'boolean'];

    public static function sharesFor(int $userId): bool
    {
        return (bool) (static::query()->whereKey($userId)->value('share') ?? true);
    }
}
