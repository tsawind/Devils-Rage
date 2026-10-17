<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Patch 39: a one-time reminder, logged so nobody gets it twice. kind "seat" (a SeAT user,
 * target = user id, DM to their linked Discord) or "discord" (a Discord member with the
 * Devil role who isn't on SeAT, target = Discord user id).
 *
 * @property int $id
 * @property string $kind
 * @property string $target
 * @property string|null $name
 * @property list<string>|null $missing
 * @property bool $sent
 * @property string|null $error
 * @property int|null $sent_by
 */
class Reminder extends Model
{
    protected $table = 'devilsrage_reminders';

    protected $fillable = ['kind', 'target', 'name', 'missing', 'sent', 'error', 'sent_by'];

    /** @var array<string, string> */
    protected $casts = ['missing' => 'array', 'sent' => 'boolean'];
}
