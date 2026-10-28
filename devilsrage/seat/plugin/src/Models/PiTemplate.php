<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Patch 59: an EVE PI colony template a pilot keeps on the PI screen (shared with everyone on
 * our SeAT unless they untick it). body is the template exactly as EVE copied it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $note
 * @property string $body
 * @property string $fingerprint
 * @property int $planet_type_id
 * @property bool $shared
 */
class PiTemplate extends Model
{
    protected $table = 'devilsrage_pi_templates';

    protected $fillable = ['user_id', 'name', 'note', 'body', 'fingerprint', 'planet_type_id', 'shared'];

    /** @var array<string, string> */
    protected $casts = ['user_id' => 'integer', 'planet_type_id' => 'integer', 'shared' => 'boolean'];
}
