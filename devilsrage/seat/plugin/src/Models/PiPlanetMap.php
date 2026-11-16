<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The planet scan (remake): one planet's sky or bare map, shared with the corp (see Pi\PlanetMaps).
 *
 * @property int $id
 * @property int $planet_id
 * @property string $kind
 * @property string $data
 * @property int $bytes
 * @property float $coverage
 * @property string|null $meta
 * @property int $user_id
 * @property int|null $character_id
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PiPlanetMap extends Model
{
    protected $table = 'devilsrage_pi_planet_maps';

    protected $fillable = ['planet_id', 'kind', 'data', 'bytes', 'coverage', 'meta', 'user_id', 'character_id'];

    /** @var array<string, string> */
    protected $casts = ['planet_id' => 'integer', 'bytes' => 'integer', 'user_id' => 'integer', 'character_id' => 'integer', 'coverage' => 'float'];
}
