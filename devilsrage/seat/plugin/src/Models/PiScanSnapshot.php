<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/** One saved scan of a planet's resource, as it was that day (the history behind PiScan). */
class PiScanSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'devilsrage_pi_scan_snapshots';

    protected $fillable = ['planet_id', 'resource', 'user_id', 'character_id', 'cells', 'coverage'];

    protected $casts = ['planet_id' => 'integer', 'user_id' => 'integer', 'character_id' => 'integer', 'coverage' => 'float'];
}
