<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 46: one of the desktop app's settings for everyone (see DesktopAppConfig).
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by_name
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $updated_at
 */
final class AppSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
