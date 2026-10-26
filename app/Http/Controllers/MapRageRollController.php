<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapRageRoll\NewStaticAction;
use App\Actions\MapRageRoll\ResolveRageRollTargetsAction;
use App\Actions\MapRageRoll\StartRageRollAction;
use App\Actions\MapRageRoll\StopRageRollAction;
use App\Http\Requests\RageRollCollapsedRequest;
use App\Http\Requests\RageRollNewStaticRequest;
use App\Http\Requests\StartRageRollRequest;
use App\Http\Requests\UpdateRageRollTargetsRequest;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\Signature;
use App\Models\User;
use App\Support\Broadcasting\MapBroadcaster;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 35: RAGE ROLL. Start (with an optional Discord ping), change the target
 * systems, stop (anyone who can map), and "New Alpha?" → make a freshly pasted
 * signature the static while the old static's chain is stamped.
 */
final class MapRageRollController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(StartRageRollRequest $request, Map $map, #[CurrentUser] User $user, StartRageRollAction $action): RedirectResponse
    {
        /** @var array{solarsystem_id: int, map_webhook_id?: int|null, mention?: string|null, system_text?: string|null, static_text?: string|null, kspace_text?: string|null, note?: string|null, targets?: list<string>|null, scanning?: bool|null} $data */
        $data = $request->validated();
        $action->handle($map, $user, $data);

        return back()->notify('Rage roll started', message: filled($data['map_webhook_id'] ?? null) ? 'Posted to Discord.' : 'No ping sent.');
    }

    /**
     * @throws ValidationException
     */
    public function targets(UpdateRageRollTargetsRequest $request, Map $map, ResolveRageRollTargetsAction $resolve, MapBroadcaster $mapBroadcaster): RedirectResponse
    {
        if ($map->rage_roll_solarsystem_id === null) {
            throw ValidationException::withMessages(['targets' => 'Nobody is rage rolling right now.']);
        }

        /** @var list<string> $names */
        $names = $request->validated('targets');
        $map->update(['rage_roll_targets' => $resolve->handle($names)]);
        $mapBroadcaster->metadataUpdated($map);

        return back();
    }

    /**
     * Patch 57: the static collapsed: everyone's timer counts from this moment. A time sent by the
     * mapper (when the collapsing jump arrived) is kept when it is within the last two minutes;
     * otherwise, and when none is sent, it's now. A second report replaces the first.
     *
     * @throws ValidationException
     */
    public function collapsed(RageRollCollapsedRequest $request, Map $map, #[CurrentUser] User $user, MapBroadcaster $mapBroadcaster): RedirectResponse
    {
        if ($map->rage_roll_solarsystem_id === null) {
            throw ValidationException::withMessages(['at' => 'Nobody is rage rolling right now.']);
        }

        $now = CarbonImmutable::now();
        $at = $request->filled('at') ? CarbonImmutable::parse((string) $request->string('at')) : $now;
        if ($at->gt($now) || $at->lt($now->subMinutes(2))) {
            $at = $now;
        }

        $map->update([
            'rage_roll_collapsed_at' => $at,
            'rage_roll_collapsed_by' => $request->filled('character_name') ? (string) $request->string('character_name') : ($user->active_character->name ?? null),
        ]);
        $mapBroadcaster->metadataUpdated($map);

        return back();
    }

    public function destroy(Map $map, #[CurrentUser] User $user, StopRageRollAction $action): RedirectResponse
    {
        Gate::authorize('update', $map);

        $action->handle($map, $user);

        return back()->notify('Rage roll over');
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function newStatic(RageRollNewStaticRequest $request, Map $map, NewStaticAction $action, MapBroadcaster $mapBroadcaster): RedirectResponse
    {
        $old = Signature::query()->findOrFail($request->integer('old_signature_id'));
        $new = Signature::query()->findOrFail($request->integer('signature_id'));
        $rolling = MapSolarsystem::query()->where('map_id', $map->id)->findOrFail($old->map_solarsystem_id);

        $result = $action->handle($rolling, $old, $new);

        // Patch 57: the new static is in: the roll timer is done.
        if ($map->rage_roll_collapsed_at !== null) {
            $map->update(['rage_roll_collapsed_at' => null, 'rage_roll_collapsed_by' => null]);
            $mapBroadcaster->metadataUpdated($map);
        }

        $message = $result['stamp'] !== null
            ? sprintf('The old chain is now %s@%s (%d system%s)%s.', $result['alias'], $result['stamp'], $result['stamped'], $result['stamped'] === 1 ? '' : 's', $result['hidden'] > 0 ? ', hidden' : ', still shown (pilot inside, pinned or another exit)')
            : 'The old static was never jumped: its signature is gone.';

        return back()->notify('New static', message: $message);
    }
}
