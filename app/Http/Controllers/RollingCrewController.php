<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\Map;
use App\Models\RollingCrew;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Patch 37: rolling crews. Each person makes their own (ordered characters, expendable ones,
 * "can swap pilots" for juggling); everyone above viewer sees and uses them, only the owner
 * changes them.
 */
final class RollingCrewController extends Controller
{
    public function store(Request $request, Map $map, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $map);
        $crew = RollingCrew::query()->create([...$this->validated($request), 'user_id' => $user->id, 'owner_name' => Actor::name() ?? $user->name]);

        return response()->json(['crew' => $crew]);
    }

    public function update(Request $request, Map $map, RollingCrew $rollingCrew, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $map);
        abort_unless($rollingCrew->user_id === $user->id, 403, 'Only its owner changes a crew.');
        $rollingCrew->forceFill($this->validated($request))->save();

        return response()->json(['crew' => $rollingCrew]);
    }

    public function destroy(Map $map, RollingCrew $rollingCrew, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $map);
        abort_unless($rollingCrew->user_id === $user->id, 403, 'Only its owner removes a crew.');
        $rollingCrew->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * A character for a crew by its exact name (characters the mapper knows).
     */
    public function lookup(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('update', $map);
        $validated = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $character = Character::query()->where('name', $validated['name'])->first(['id', 'name']);

        return response()->json(['character' => $character instanceof Character ? ['character_id' => $character->id, 'name' => $character->name] : null]);
    }

    /**
     * @return array{name: string, can_swap: bool, members: list<array{character_id: int, name: string, expendable: bool}>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'can_swap' => ['sometimes', 'boolean'],
            'members' => ['present', 'array', 'max:30'],
            'members.*.character_id' => ['required', 'integer'],
            'members.*.name' => ['required', 'string', 'max:80'],
            'members.*.expendable' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => (string) $validated['name'],
            'can_swap' => (bool) ($validated['can_swap'] ?? false),
            'members' => array_values(array_map(fn (array $member): array => [
                'character_id' => (int) $member['character_id'],
                'name' => (string) $member['name'],
                'expendable' => (bool) ($member['expendable'] ?? false),
            ], $validated['members'])),
        ];
    }
}
