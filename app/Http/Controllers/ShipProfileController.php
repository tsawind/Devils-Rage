<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterStatus;
use App\Models\EsiToken;
use App\Models\Map;
use App\Models\ShipProfile;
use App\Models\Type;
use App\Services\Seat\SeatFits;
use App\Support\Actor;
use App\Support\PilotJumpSkills;
use App\Support\ShipMass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use NicolasKion\Esi\DTO\Ship;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 37: ship profiles: each pilot's hull with its real cold / hot jump mass. Read from
 * the fit SeAT knows (Higgs, plates, prop mod), or typed in (then SeAT never overwrites it
 * until it's removed). Shared with the corp; used by the Fleet window, the rolling plans and
 * the jump log.
 */
final class ShipProfileController extends Controller
{
    public function index(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('view', $map);
        $validated = $request->validate(['character_ids' => ['required', 'array', 'max:200'], 'character_ids.*' => ['integer']]);

        return response()->json(['profiles' => $this->profiles(array_map(intval(...), $validated['character_ids']))]);
    }

    public function update(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('update', $map);
        $validated = $request->validate([
            'character_id' => ['required', 'integer'],
            'ship_type_id' => ['required', 'integer', 'exists:types,id'],
            'cold_mass' => ['required', 'integer', 'min:0', 'max:3000000000000'],
            'hot_mass' => ['required', 'integer', 'min:0', 'max:3000000000000', 'gte:cold_mass'],
            // Patch 37: a capital's smaller prop (SEMI, e.g. a carrier's 500MN), and its plates taken off.
            'semi_mass' => ['nullable', 'integer', 'gte:cold_mass', 'lte:hot_mass'],
            'plates_off' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        $semi = isset($validated['semi_mass']) && $validated['semi_mass'] > $validated['cold_mass'] && $validated['semi_mass'] < $validated['hot_mass'] ? (int) $validated['semi_mass'] : null;
        $options = array_values(array_filter([
            $validated['hot_mass'] > $validated['cold_mass'] ? ['key' => 'hot0', 'label' => 'HOT', 'mode' => 'hot', 'mass' => (int) $validated['hot_mass']] : null,
            $semi !== null ? ['key' => 'hot1', 'label' => 'SEMI', 'mode' => 'hot', 'mass' => $semi] : null,
            ['key' => 'cold', 'label' => 'COLD', 'mode' => 'cold', 'mass' => (int) $validated['cold_mass']],
        ]));
        $note = $validated['note'] ?? null;
        if (($validated['plates_off'] ?? false) && ! str_contains((string) $note, 'plates off')) {
            $note = trim('plates off '.($note ?? ''));
        }

        ShipProfile::query()->updateOrCreate(
            ['character_id' => $validated['character_id'], 'ship_type_id' => $validated['ship_type_id']],
            [
                'cold_mass' => $validated['cold_mass'],
                'hot_mass' => $validated['hot_mass'],
                'note' => $note,
                'source' => 'manual',
                'options' => $options,
                // Patch 53: made for the ship the pilot flies now.
                'ship_item_id' => CharacterStatus::query()->where('character_id', $validated['character_id'])->where('ship_type_id', $validated['ship_type_id'])->value('ship_item_id'),
                'updated_by_name' => Actor::name(),
            ],
        );

        return response()->json(['profiles' => $this->profiles([(int) $validated['character_id']])]);
    }

    public function destroy(Request $request, Map $map): JsonResponse
    {
        Gate::authorize('update', $map);
        $validated = $request->validate(['character_id' => ['required', 'integer'], 'ship_type_id' => ['required', 'integer']]);

        ShipProfile::query()->where('character_id', $validated['character_id'])->where('ship_type_id', $validated['ship_type_id'])->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Read the fits SeAT knows for these pilots' current hulls (an hour at most per pilot).
     */
    public function refresh(Request $request, Map $map, SeatFits $seatFits, Esi $esi, PilotJumpSkills $pilotJumpSkills): JsonResponse
    {
        Gate::authorize('view', $map);
        $validated = $request->validate([
            'members' => ['required', 'array', 'max:100'],
            'members.*.character_id' => ['required', 'integer'],
            'members.*.ship_type_id' => ['required', 'integer'],
        ]);

        $ids = [];
        foreach ($validated['members'] as $member) {
            $characterId = (int) $member['character_id'];
            $shipTypeId = (int) $member['ship_type_id'];
            $ids[] = $characterId;
            $existing = ShipProfile::query()->where('character_id', $characterId)->where('ship_type_id', $shipTypeId)->first();
            // Patch 53: a hand-made profile stays, unless it was made for another ship of this type.
            $flying = CharacterStatus::query()->where('character_id', $characterId)->where('ship_type_id', $shipTypeId)->value('ship_item_id');
            $flying = $flying === null ? null : (int) $flying;
            if ($existing instanceof ShipProfile && $existing->source === 'manual' && $existing->fitsShip($flying)) {
                continue;
            }
            if (! Type::query()->whereKey($shipTypeId)->exists()) {
                continue;
            }

            // Patch 53: read again for another ship of the same type (a new hull after a loss).
            Cache::remember(sprintf('ship-profile-read:v3:%d:%d:%s', $characterId, $shipTypeId, $flying ?? '-'), now()->addHour(), function () use ($characterId, $shipTypeId, $flying, $seatFits, $esi, $pilotJumpSkills): bool {
                $itemId = $this->shipItemId($characterId, $shipTypeId, $esi) ?? $flying;
                $fit = $seatFits->fitFor($characterId, $shipTypeId, $itemId);
                if ($fit === null) {
                    $masses = ShipMass::bareHull($shipTypeId);
                    ShipProfile::query()->updateOrCreate(
                        ['character_id' => $characterId, 'ship_type_id' => $shipTypeId],
                        ['cold_mass' => $masses['cold'], 'hot_mass' => $masses['hot'], 'higgs' => false, 'plates' => 0, 'entanglers' => 0, 'prop_mass' => 0, 'modules' => null, 'options' => null, 'source' => 'hull', 'fitted_at' => null, 'ship_item_id' => $itemId],
                    );

                    return true;
                }

                // Plates weigh less with Armor Layering / Advanced Armor Layering (when the pilot shares skills).
                $character = Character::query()->find($characterId);
                $skills = $character instanceof Character ? $pilotJumpSkills->levels($character) : null;
                $plateMass = ShipMass::plateMass($fit['plate_mass'], $skills['armor_layering'] ?? 0, $skills['advanced_armor_layering'] ?? 0);
                $options = ShipMass::options($shipTypeId, $fit['higgs'], $plateMass, $fit['props'], $fit['entanglers']);
                $cold = collect($options)->firstWhere('key', 'cold')['mass'] ?? 0;
                $hot = collect($options)->where('mode', 'hot')->max('mass') ?? $cold;
                ShipProfile::query()->updateOrCreate(
                    ['character_id' => $characterId, 'ship_type_id' => $shipTypeId],
                    [
                        'cold_mass' => (int) $cold,
                        'hot_mass' => (int) $hot,
                        'higgs' => $fit['higgs'],
                        'plates' => $fit['plates'],
                        'entanglers' => $fit['entanglers'],
                        'prop_mass' => (int) round($fit['prop_mass']),
                        'plate_mass' => (int) round($plateMass),
                        'modules' => $fit['modules'],
                        'options' => $options,
                        'source' => 'seat',
                        'fitted_at' => now(),
                        'ship_item_id' => $itemId,
                    ],
                );

                return true;
            });
        }

        return response()->json(['profiles' => $this->profiles($ids)]);
    }

    /** The exact ship a character sits in, when it's in the mapper with the ship permission. */
    private function shipItemId(int $characterId, int $shipTypeId, Esi $esi): ?int
    {
        $character = Character::query()->find($characterId);
        if (! $character instanceof Character || ! $character->getEsiTokenWithScope(EsiScope::ReadShip) instanceof EsiToken) {
            return null;
        }
        try {
            $result = $esi->getShip($character);
        } catch (Throwable) {
            return null;
        }

        /** @var mixed $data */
        $data = $result->data;

        return $data instanceof Ship && $data->ship_type_id === $shipTypeId ? $data->ship_item_id : null;
    }

    /**
     * @param  list<int>  $characterIds
     * @return list<array<string, mixed>>
     */
    private function profiles(array $characterIds): array
    {
        return ShipProfile::query()
            ->with('shipType:id,name')
            ->whereIn('character_id', $characterIds)
            ->get()
            ->map(fn (ShipProfile $profile): array => [
                'character_id' => $profile->character_id,
                'ship_type_id' => $profile->ship_type_id,
                'ship' => $profile->shipType?->name,
                'cold_mass' => $profile->cold_mass,
                'hot_mass' => $profile->hot_mass,
                'higgs' => $profile->higgs,
                'plates' => $profile->plates,
                'entanglers' => $profile->entanglers,
                'modules' => $profile->modules ?? [],
                'options' => $profile->options ?? [],
                'prop_mass' => $profile->prop_mass,
                'plate_mass' => (int) $profile->getAttribute('plate_mass'),
                'source' => $profile->source,
                'note' => $profile->note,
                'updated_by_name' => $profile->updated_by_name,
                'fitted_at' => $profile->fitted_at?->toISOString(),
            ])
            ->values()
            ->all();
    }
}
