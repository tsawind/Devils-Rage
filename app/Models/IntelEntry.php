<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 55: one line of a system's intel log: a structure added / changed / removed, a D-scan,
 * a fit, a screenshot or a note. Kept for good.
 *
 * @property int $id
 * @property int $map_id
 * @property int $solarsystem_id
 * @property int|null $intel_structure_id
 * @property string $kind
 * @property string|null $body
 * @property array<string, mixed>|null $data
 * @property string|null $image_path
 * @property string|null $author_name
 * @property-read CarbonImmutable $created_at
 */
final class IntelEntry extends Model
{
    public const array KINDS = ['structure', 'dscan', 'fit', 'screenshot', 'note'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
