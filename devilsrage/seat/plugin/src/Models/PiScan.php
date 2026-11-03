<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Patch 71: a whole-planet heat map of one resource, from the desktop app's video scan.
 * cells is 360 × 180 bytes (see Pi\PiScan); shared with the corp.
 *
 * @property int $id
 * @property int $planet_id
 * @property string $resource
 * @property int $user_id
 * @property int|null $character_id
 * @property string $cells
 * @property float $coverage
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PiScan extends Model
{
    protected $table = 'devilsrage_pi_scans';

    protected $fillable = ['planet_id', 'resource', 'user_id', 'character_id', 'cells', 'coverage'];

    /** @var array<string, string> */
    protected $casts = ['planet_id' => 'integer', 'user_id' => 'integer', 'character_id' => 'integer', 'coverage' => 'float'];
}
