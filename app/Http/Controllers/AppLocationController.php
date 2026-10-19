<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tracking\ReportAppLocationAction;
use App\Http\Requests\ReportAppLocationRequest;
use App\Models\Character;
use App\Models\Solarsystem;
use Illuminate\Http\JsonResponse;

/**
 * Patch 46: the desktop app saw one of your characters change system in EVE's Local chat log.
 */
final class AppLocationController extends Controller
{
    public function store(ReportAppLocationRequest $request, ReportAppLocationAction $action): JsonResponse
    {
        $character = Character::query()->findOrFail((int) $request->validated('character_id'));
        $solarsystem = Solarsystem::query()->where('name', (string) $request->validated('solarsystem'))->firstOrFail();

        return response()->json(['moved' => $action->handle($character, $solarsystem)]);
    }
}
