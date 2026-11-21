<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 20 Nov: a player structure's name, as EVE gave it to a pilot who can dock there.
 *
 * @property int $id the structure's EVE id
 * @property string $name
 * @property int|null $type_id
 * @property int|null $solarsystem_id
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $updated_at
 */
final class StructureName extends Model
{
    public $incrementing = false;

    /** The name of a structure, when it has been read (kept in the cache once known). */
    public static function nameOf(?int $structureId): ?string
    {
        if ($structureId === null || $structureId <= 0) {
            return null;
        }
        $key = self::cacheKey($structureId);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $name = self::query()->whereKey($structureId)->value('name');
        if (! is_string($name)) {
            return null;
        }
        Cache::put($key, $name, now()->addDay());

        return $name;
    }

    public static function cacheKey(int $structureId): string
    {
        return sprintf('structure-name:%d', $structureId);
    }

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'type_id' => 'integer',
            'solarsystem_id' => 'integer',
        ];
    }
}
