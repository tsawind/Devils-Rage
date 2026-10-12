<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property bool $share
 */
class AccountSetting extends Model
{
    public $incrementing = false;

    protected $table = 'devilsrage_account_settings';

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'share'];

    /** @var array<string, string> */
    protected $casts = ['share' => 'boolean'];

    public static function sharesFor(int $userId): bool
    {
        return (bool) (static::query()->whereKey($userId)->value('share') ?? true);
    }
}
