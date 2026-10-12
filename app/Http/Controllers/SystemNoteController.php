<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapSolarsystem\UpdateMapSolarsystemAction;
use App\Models\MapSolarsystem;
use App\Models\MapSolarsystemDetails;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: right-click a system → Add note…: the text goes at the END of its notes, never
 * over them, stamped with the EVE date and time and the human (the SeAT main, else the
 * character): "**2026-10-11 15:18 EVE · Tsawind:** Tengu + 2 Lokis on scan".
 */
final class SystemNoteController extends Controller
{
    public function store(Request $request, MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user, UpdateMapSolarsystemAction $action): RedirectResponse
    {
        Gate::authorize('update', $mapSolarsystem);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ]);

        $character = $user->active_character;
        $who = $character instanceof \App\Models\Character ? ($character->seat_main_name ?? $character->name) : $user->name;
        $stamp = sprintf('**%s EVE · %s:**', now('UTC')->format('Y-m-d H:i'), $who);
        $text = mb_trim(str_replace("\r\n", "\n", (string) $validated['text']));

        DB::transaction(function () use ($mapSolarsystem, $stamp, $text, $action): void {
            $details = MapSolarsystemDetails::query()->whereKey($mapSolarsystem->map_solarsystem_details_id)->lockForUpdate()->first();
            $existing = mb_rtrim($details instanceof MapSolarsystemDetails ? (string) $details->notes : '');
            $action->handle($mapSolarsystem, ['notes' => ($existing === '' ? '' : $existing."\n\n").$stamp.' '.$text]);
        });

        return back();
    }
}
