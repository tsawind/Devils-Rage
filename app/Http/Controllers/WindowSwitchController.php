<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\Characters\WindowSwitchRequestedEvent;
use App\Http\Requests\SwitchWindowRequest;
use App\Models\Character;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Patch 45d: from the main window, switch a character's own window (any browser) to another
 * character. The window hears it on your private channel and switches itself and its pop-out.
 */
final class WindowSwitchController extends Controller
{
    public function store(SwitchWindowRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = Character::query()->findOrFail((int) $request->validated('to_character_id'));

        event(new WindowSwitchRequestedEvent($user->id, (int) $request->validated('from_character_id'), $to->id, $to->name));

        return back();
    }
}
