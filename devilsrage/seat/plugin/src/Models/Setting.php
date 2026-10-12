<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small corp-wide settings of the Devil's Rage pages (key → value).
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $table = 'devilsrage_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function read(string $key, ?string $default = null): ?string
    {
        $value = static::query()->whereKey($key)->value('value');

        return $value === null || $value === '' ? $default : (string) $value;
    }

    public static function write(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
