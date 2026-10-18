<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCharacterColorRequest;
use App\Models\Character;
use Illuminate\Http\RedirectResponse;

/**
 * Patch 41: set (or clear) a character's colour: everyone on the map sees it.
 */
final class CharacterColorController extends Controller
{
    public function update(UpdateCharacterColorRequest $request, Character $character): RedirectResponse
    {
        $color = $request->validated('color');
        $character->forceFill(['map_color' => is_string($color) ? mb_strtolower($color) : null])->save();

        return back();
    }
}
