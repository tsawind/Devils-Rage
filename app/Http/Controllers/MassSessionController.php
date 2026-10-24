<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapConnections\UpdateMapConnectionAction;
use App\Data\MapConnectionData;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\MassObservation;
use App\Models\MassSession;
use App\Models\RollingCrew;
use App\Models\ShipProfile;
use App\Models\Type;
use App\Models\User;
use App\Support\Actor;
use App\Support\Broadcasting\MapBroadcaster;
use App\Support\MassSessions;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Patch 37: "⚖ Adding mass…": sessions on holes (everyone on the map sees them), what people
 * saw on each hole (marks and checks with the mass logged then, "made by the last jump"),
 * the plan the crew follows, and everyone's rolling crews.
 */
final class MassSessionController extends Controller
{
    public const array GOALS = ['watch', 'reduced', 'crit', 'roll', 'closer'];

    public function __construct(private readonly MapBroadcaster $mapBroadcaster) {}

    public function index(Map $map): JsonResponse
    {
        Gate::authorize('view', $map);

        $sessions = MassSession::query()->where('map_id', $map->id)->whereNull('ended_at')->orderBy('id')->get();
        $connectionIds = $sessions->pluck('map_connection_id')->unique()->values()->all();

        return response()->json([
            'sessions' => $sessions->map(fn (MassSession $session): array => $this->sessionPayload($session))->values(),
            'observations' => MassObservation::query()
                ->whereIn('map_connection_id', $connectionIds)
                ->orderBy('id')
                ->get(['id', 'map_connection_id', 'status', 'logged_mass', 'flip_mass', 'flip_by', 'by_name', 'created_at'])
                ->groupBy('map_connection_id'),
            'logged' => collect($connectionIds)->mapWithKeys(fn (int $id): array => [$id => MassObservation::loggedMass($id)]),
            // The last ten minutes of jumps on those holes: polarization (5 min per pilot, per direction).
            'recent_jumps' => MapConnectionJump::query()
                ->whereIn('map_connection_id', $connectionIds)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->orderBy('id')
                ->get(['id', 'map_connection_id', 'character_id', 'from_solarsystem_id', 'to_solarsystem_id', 'mass', 'created_at'])
                ->groupBy('map_connection_id'),
            'crews' => $this->crews(),
        ]);
    }

    public function store(Request $request, Map $map, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $map);
        $validated = $request->validate([
            'map_connection_id' => ['required', 'integer', Rule::exists('map_connections', 'id')->where('map_id', $map->id)],
            'home_map_solarsystem_id' => ['nullable', 'integer'],
            'nominal' => ['nullable', 'integer', 'min:1'],
        ]);

        $session = MassSession::query()->where('map_connection_id', $validated['map_connection_id'])->whereNull('ended_at')->first()
            ?? MassSession::query()->create([
                'map_id' => $map->id,
                'map_connection_id' => $validated['map_connection_id'],
                'user_id' => $user->id,
                'started_by_name' => Actor::name() ?? $user->name,
                'goal' => 'watch',
                'home_map_solarsystem_id' => $validated['home_map_solarsystem_id'] ?? null,
                'nominal_mass' => $validated['nominal'] ?? null,
            ]);

        $this->broadcastConnection($session->map_connection_id);

        return response()->json(['session' => $this->sessionPayload($session)]);
    }

    public function update(Request $request, Map $map, MassSession $massSession): JsonResponse
    {
        Gate::authorize('update', $map);
        abort_unless($massSession->map_id === $map->id, 404);
        $validated = $request->validate([
            'goal' => ['sometimes', 'string', Rule::in(self::GOALS)],
            'crew_ids' => ['sometimes', 'array', 'max:10'],
            'crew_ids.*' => ['integer'],
            'only_our_jumps' => ['sometimes', 'boolean'],
            'hurry' => ['sometimes', 'boolean'],
            'home_map_solarsystem_id' => ['sometimes', 'nullable', 'integer'],
            'plan' => ['sometimes', 'nullable', 'array'],
            'plan.steps' => ['sometimes', 'array', 'max:200'],
            'plan.steps.*.character_id' => ['required_with:plan.steps', 'integer'],
            'plan.steps.*.direction' => ['required_with:plan.steps', 'string', Rule::in(['out', 'home'])],
            'plan.steps.*.mass' => ['required_with:plan.steps', 'integer', 'min:0'],
            'plan.steps.*.label' => ['nullable', 'string', 'max:80'],
            'plan.steps.*.name' => ['nullable', 'string', 'max:80'],
            'nominal' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $fill = collect($validated)->except(['plan', 'nominal'])->all();
        if (($validated['nominal'] ?? null) !== null) {
            $fill['nominal_mass'] = $validated['nominal'];
        }
        if (array_key_exists('plan', $validated)) {
            $fill['plan'] = $validated['plan'] === null ? null : ['steps' => array_map(fn (array $step): array => [...$step, 'done' => false], $validated['plan']['steps'] ?? [])];
            $fill['plan_started_at'] = $validated['plan'] === null ? null : now();
        }
        $massSession->forceFill($fill)->save();
        MassSessions::keepFromSession($massSession);
        $this->broadcastConnection($massSession->map_connection_id);

        return response()->json(['session' => $this->sessionPayload($massSession->fresh() ?? $massSession)]);
    }

    /**
     * Patch 52: end it, keeping what it worked out: "top" (done massing), "watch" (done and
     * watching) or "forget" (the old ✕).
     */
    public function destroy(Request $request, Map $map, MassSession $massSession): JsonResponse
    {
        Gate::authorize('update', $map);
        abort_unless($massSession->map_id === $map->id, 404);
        $how = $request->validate(['keep' => ['sometimes', 'string', Rule::in(['top', 'watch', 'forget'])]])['keep'] ?? 'forget';
        $massSession->forceFill(['ended_at' => now()])->save();
        $connection = MapConnection::query()->find($massSession->map_connection_id);
        if ($connection instanceof MapConnection && ($how === 'forget' || $connection->kept_size_from === 'session')) {
            MassSessions::endKept($connection, $how, Actor::name());
        }
        $this->broadcastConnection($massSession->map_connection_id);

        return response()->json(['ok' => true]);
    }

    /**
     * "Status now?": mark the hole (as from the pipe's menu) and, when ticked, say the last
     * logged jump made it so ("crit, made by DeltaMechanic's 32.4m").
     */
    public function observe(Request $request, Map $map, MassSession $massSession, UpdateMapConnectionAction $updateMapConnectionAction): JsonResponse
    {
        Gate::authorize('update', $map);
        abort_unless($massSession->map_id === $map->id, 404);
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['fresh', 'reduced', 'critical'])],
            'made_by_last_jump' => ['sometimes', 'boolean'],
            'nominal' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        if (($validated['nominal'] ?? null) !== null) {
            $massSession->forceFill(['nominal_mass' => $validated['nominal']])->save();
        }

        $connection = MapConnection::query()->findOrFail($massSession->map_connection_id);
        $before = (int) MassObservation::query()->where('map_connection_id', $connection->id)->max('id');
        $updateMapConnectionAction->handle($connection, MapConnectionData::from(['mass_status' => $validated['status']]));

        // The mark notes what was seen when a pilot made it; make sure it's noted either way.
        $observation = MassObservation::query()->where('map_connection_id', $connection->id)->where('id', '>', $before)->latest('id')->first()
            ?? MassObservation::note($connection->id, $validated['status'], Actor::name());
        if ($validated['made_by_last_jump'] ?? false) {
            $last = MapConnectionJump::query()->where('map_connection_id', $connection->id)->latest('id')->first();
            if ($last instanceof MapConnectionJump) {
                $observation->forceFill([
                    'flip_mass' => $last->mass,
                    'flip_by' => $last->character_id === null ? null : Character::query()->whereKey($last->character_id)->value('name'),
                ])->save();
            }
        }
        MassSessions::keepFromSession($massSession);
        $this->broadcastConnection($connection->id);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(MassSession $session): array
    {
        return [
            'id' => $session->id,
            'map_connection_id' => $session->map_connection_id,
            'started_by_name' => $session->started_by_name,
            'user_id' => $session->user_id,
            'goal' => $session->goal,
            'crew_ids' => $session->crew_ids ?? [],
            'only_our_jumps' => $session->only_our_jumps,
            'hurry' => $session->hurry,
            'home_map_solarsystem_id' => $session->home_map_solarsystem_id,
            'plan' => $session->plan,
            'plan_started_at' => $session->plan_started_at?->toISOString(),
            'created_at' => $session->created_at->toISOString(),
        ];
    }

    /**
     * Everyone's crews, each member with the ship they fly now (the mapper's own characters)
     * or the last ship they had a profile for.
     *
     * @return list<array<string, mixed>>
     */
    private function crews(): array
    {
        $crews = RollingCrew::query()->orderBy('owner_name')->orderBy('name')->get();
        $ids = $crews->flatMap(fn (RollingCrew $crew): array => array_map(fn (array $member): int => (int) $member['character_id'], $crew->members))->unique()->values()->all();
        $characters = Character::query()->with('characterStatus')->whereIn('id', $ids)->get()->keyBy('id');
        $profiles = ShipProfile::query()->whereIn('character_id', $ids)->orderByDesc('updated_at')->get()->groupBy('character_id');
        $typeIds = [];
        foreach ($ids as $id) {
            $typeIds[$id] = $characters->get($id)->characterStatus->ship_type_id ?? $profiles->get($id)?->first()?->ship_type_id;
        }
        $types = Type::query()->whereIn('id', array_filter($typeIds))->get(['id', 'name', 'mass'])->keyBy('id');

        return $crews->map(fn (RollingCrew $crew): array => [
            'id' => $crew->id,
            'user_id' => $crew->user_id,
            'owner_name' => $crew->owner_name,
            'name' => $crew->name,
            'can_swap' => $crew->can_swap,
            'members' => array_map(function (array $member) use ($typeIds, $types): array {
                $typeId = $typeIds[(int) $member['character_id']] ?? null;
                $type = $typeId === null ? null : $types->get($typeId);

                return [
                    'character_id' => (int) $member['character_id'],
                    'name' => (string) $member['name'],
                    'expendable' => (bool) ($member['expendable'] ?? false),
                    'ship_type_id' => $typeId,
                    'ship' => $type?->name,
                    'hull_mass' => (int) round((float) ($type->mass ?? 0)),
                    'jump_mass' => $typeId === null ? 0 : Type::loggedJumpMass($typeId),
                ];
            }, $crew->members),
        ])->values()->all();
    }

    private function broadcastConnection(int $connectionId): void
    {
        MassSessions::forget();
        $connection = MapConnection::query()->whereKey($connectionId)->with('signatures.signatureType', 'signatures.wormhole')->withJumpSummary()->get();
        $first = $connection->first();
        if ($first instanceof MapConnection) {
            $this->mapBroadcaster->connectionsUpserted($first->map_id, $connection);
        }
    }
}
