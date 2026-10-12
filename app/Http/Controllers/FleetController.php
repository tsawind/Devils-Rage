<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Models\Type;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use NicolasKion\Esi\DTO\FleetInfo;
use NicolasKion\Esi\DTO\FleetMember;
use NicolasKion\Esi\DTO\FleetSquad;
use NicolasKion\Esi\DTO\FleetWing;
use NicolasKion\Esi\DTO\Name;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 37: the fleet you're in, for the fleet card and "Will it hold?": every member's hull
 * and its mass. EVE only lets the fleet boss read the members, so the mapper reads them with
 * the boss's token (the boss has to be in the mapper with the Read fleet permission).
 * Cached 20 s per fleet.
 */
final class FleetController extends Controller
{
    /** A hull this heavy or more is a capital (they start unticked on the card). */
    public const int CAPITAL_MASS = 500_000_000;

    public function show(Map $map, #[CurrentUser] User $user, Esi $esi): JsonResponse
    {
        Gate::authorize('view', $map);

        $info = $this->fleetInfo($user, $esi);
        if ($info === null) {
            return response()->json(['status' => 'no_fleet', 'message' => 'None of your characters is in a fleet (or none has the Read fleet permission).']);
        }

        $boss = Character::query()->whereKey($info->fleet_boss_id)->first();
        if (! $boss instanceof Character || ! $boss->getEsiTokenWithScope(EsiScope::ReadFleet) instanceof EsiToken) {
            return response()->json([
                'status' => 'boss_missing',
                'fleet_id' => $info->fleet_id,
                'message' => sprintf('EVE only lets the fleet boss read the members: %s needs to be in the mapper with the Read fleet permission.', $this->names([$info->fleet_boss_id])[$info->fleet_boss_id] ?? 'the boss'),
            ]);
        }

        /** @var array{members: list<array<string, mixed>>, updated_at: string}|null $cached */
        $cached = Cache::remember(sprintf('fleet:%d', $info->fleet_id), now()->addSeconds(20), fn (): ?array => $this->members($boss, $info->fleet_id, $esi));
        if ($cached === null) {
            return response()->json(['status' => 'error', 'message' => 'EVE did not answer for the fleet members. Try again in a moment.']);
        }

        return response()->json([
            'status' => 'ok',
            'fleet_id' => $info->fleet_id,
            'boss' => $boss->name,
            ...$cached,
        ]);
    }

    private function fleetInfo(User $user, Esi $esi): ?FleetInfo
    {
        $characters = $user->characters()->get()
            ->sortByDesc(fn (Character $character): bool => $character->id === $user->active_character?->id);
        foreach ($characters as $character) {
            if (! $character->getEsiTokenWithScope(EsiScope::ReadFleet) instanceof EsiToken) {
                continue;
            }
            try {
                $result = $esi->getCharacterFleet($character);
            } catch (Throwable) {
                continue;
            }
            if ($result->wasSuccessful() && $result->data instanceof FleetInfo) {
                return $result->data;
            }
        }

        return null;
    }

    /**
     * @return array{members: list<array<string, mixed>>, updated_at: string}|null
     */
    private function members(Character $boss, int $fleetId, Esi $esi): ?array
    {
        try {
            $result = $esi->getFleetMembers($boss, $fleetId);
        } catch (Throwable) {
            return null;
        }
        if ($result->failed() || ! is_array($result->data)) {
            return null;
        }

        /** @var list<FleetMember> $members */
        $members = array_values(array_filter($result->data, fn (mixed $member): bool => $member instanceof FleetMember));
        $types = Type::query()->with('group')->whereIn('id', array_unique(array_map(fn (FleetMember $member): int => $member->ship_type_id, $members)))->get()->keyBy('id');
        $systems = Solarsystem::query()->whereIn('id', array_unique(array_map(fn (FleetMember $member): int => $member->solar_system_id, $members)))->pluck('name', 'id');
        $names = $this->names(array_map(fn (FleetMember $member): int => $member->character_id, $members));
        // Patch 37: one person = one crew: their SeAT main, else their mapper account, else themselves.
        $known = Character::query()->whereIn('id', array_map(fn (FleetMember $member): int => $member->character_id, $members))->get(['id', 'user_id', 'seat_main_name'])->keyBy('id');

        $rows = [];
        foreach ($members as $member) {
            $type = $types->get($member->ship_type_id);
            $mass = (int) round((float) ($type?->mass ?? 0));
            $rows[] = [
                'character_id' => $member->character_id,
                'name' => $names[$member->character_id] ?? sprintf('#%d', $member->character_id),
                'ship_type_id' => $member->ship_type_id,
                'ship' => $type?->name,
                'group' => $type?->group?->name,
                'mass' => $mass,
                // HICs and the Odysseus count with their entangler on, as in the jump log.
                'jump_mass' => Type::loggedJumpMass($member->ship_type_id),
                'capital' => $mass >= self::CAPITAL_MASS,
                'solarsystem_id' => $member->solar_system_id,
                'solarsystem' => $systems[$member->solar_system_id] ?? null,
                'wing_id' => $member->wing_id,
                'squad_id' => $member->squad_id,
                'role' => $member->role,
                'owner' => $this->ownerOf($known->get($member->character_id), $names[$member->character_id] ?? (string) $member->character_id),
            ];
        }

        return ['members' => $rows, 'wings' => $this->wings($boss, $fleetId, $esi), 'updated_at' => now()->toISOString()];
    }

    private function ownerOf(?Character $character, string $name): string
    {
        if ($character instanceof Character && filled($character->seat_main_name)) {
            return (string) $character->seat_main_name;
        }
        if ($character instanceof Character && $character->user_id !== null) {
            $first = Character::query()->where('user_id', $character->user_id)->orderBy('id')->value('name');

            return is_string($first) ? $first : $name;
        }

        return $name;
    }

    /**
     * Wing and squad names (read with the boss's token too).
     *
     * @return list<array{id: int, name: string, squads: list<array{id: int, name: string}>}>
     */
    private function wings(Character $boss, int $fleetId, Esi $esi): array
    {
        try {
            $result = $esi->getFleetWings($boss, $fleetId);
        } catch (Throwable) {
            return [];
        }
        if ($result->failed() || ! is_array($result->data)) {
            return [];
        }

        $wings = [];
        foreach ($result->data as $wing) {
            if (! $wing instanceof FleetWing) {
                continue;
            }
            $wings[] = [
                'id' => $wing->id,
                'name' => $wing->name,
                'squads' => array_values(array_map(
                    fn (FleetSquad $squad): array => ['id' => $squad->id, 'name' => $squad->name],
                    array_filter($wing->squads, fn (mixed $squad): bool => $squad instanceof FleetSquad),
                )),
            ];
        }

        return $wings;
    }

    /**
     * Character names: the mapper's own first, the rest from EVE (cached a day).
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $known = Character::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
        $missing = array_values(array_diff($ids, array_keys($known)));
        foreach ($missing as $index => $id) {
            $cached = Cache::get(sprintf('character-name:%d', $id));
            if (is_string($cached)) {
                $known[$id] = $cached;
                unset($missing[$index]);
            }
        }
        if ($missing !== []) {
            try {
                $result = app(Esi::class)->getNames(array_values($missing));
                foreach (is_array($result->data) ? $result->data : [] as $name) {
                    if ($name instanceof Name) {
                        $known[$name->id] = $name->name;
                        Cache::put(sprintf('character-name:%d', $name->id), $name->name, now()->addDay());
                    }
                }
            } catch (Throwable) {
                // Names stay as "#id".
            }
        }

        return $known;
    }
}
