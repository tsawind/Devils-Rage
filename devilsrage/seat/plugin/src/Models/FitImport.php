<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Links a seat-fitting fit to the in-game library fit it was imported from.
 *
 * @property int $id
 * @property string $match_key
 * @property int $fitting_id
 * @property int $source_character_id
 * @property int $source_fitting_id
 * @property string $fingerprint
 */
class FitImport extends Model
{
    protected $table = 'devilsrage_fit_imports';

    protected $fillable = ['match_key', 'fitting_id', 'source_character_id', 'source_fitting_id', 'fingerprint'];

    /** @var array<string, string> SeAT 5 runs Laravel 10, so casts are a property. */
    protected $casts = [
        'fitting_id' => 'integer',
        'source_character_id' => 'integer',
        'source_fitting_id' => 'integer',
    ];
}
