<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Patch 37: the character doing something right now (the signed-in pilot's active
 * character), for "added by" / "checked by" on holes. Null outside a request
 * (scheduled jobs, queue workers): automatic changes aren't anyone's look in game.
 */
final class Actor
{
    public static function name(): ?string
    {
        $user = Auth::user();

        return $user instanceof User ? $user->active_character?->name : null;
    }
}
