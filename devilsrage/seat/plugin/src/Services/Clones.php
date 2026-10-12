<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Illuminate\Support\Carbon;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Seat\Eveapi\Models\Clones\CharacterClone;
use Seat\Eveapi\Models\Clones\CharacterImplant;
use Seat\Eveapi\Models\Clones\CharacterJumpClone;
use Seat\Eveapi\Models\Sde\InvType;

/**
 * Per character: home station, when the next clone jump is possible, implants in the
 * active clone, and every jump clone with where it is and what it carries.
 */
final class Clones
{
    /** Infomorph Synchronizing: one hour less between clone jumps per level. */
    private const SKILL_INFOMORPH_SYNC = 33399;

    public function __construct(private readonly Locations $locations) {}

    /**
     * @param  list<int>  $characterIds
     * @return list<array{character_id: int, name: string, home: string, jump_ready: Carbon|null, implants: list<string>, clones: list<array{name: string, location: string, implants: list<string>}>}>
     */
    public function forCharacters(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->pluck('name', 'character_id');
        $clones = CharacterClone::query()->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $jumpClones = CharacterJumpClone::query()->whereIn('character_id', $characterIds)->get()->groupBy('character_id');
        $implants = CharacterImplant::query()->whereIn('character_id', $characterIds)->get(['character_id', 'type_id'])->groupBy('character_id');
        $sync = CharacterSkill::query()->whereIn('character_id', $characterIds)->where('skill_id', self::SKILL_INFOMORPH_SYNC)->pluck('trained_skill_level', 'character_id');

        $typeIds = $implants->flatten()->pluck('type_id')
            ->merge($jumpClones->flatten()->flatMap(fn ($clone) => (array) $clone->implants))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $types = InvType::query()->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID');
        $places = $this->locations->resolve([
            ...$clones->pluck('home_location_id')->map(fn ($id): int => (int) $id)->all(),
            ...$jumpClones->flatten()->pluck('location_id')->map(fn ($id): int => (int) $id)->all(),
        ]);
        $systems = $this->locations->systems(array_values(array_filter(array_column($places, 'system_id'))));
        $where = function ($id) use ($places, $systems): string {
            $place = $places[(int) $id] ?? null;
            if ($place === null) {
                return $id ? 'Unknown location '.$id : '–';
            }
            $system = $place['system_id'] === null ? null : ($systems[$place['system_id']] ?? null);

            return $system !== null && ! str_starts_with($place['name'], $system) ? $system.' · '.$place['name'] : $place['name'];
        };
        $typeNames = fn (iterable $ids): array => collect($ids)->map(fn ($id): string => (string) ($types[(int) $id] ?? $id))->sort()->values()->all();

        $result = [];
        foreach ($names as $characterId => $name) {
            $clone = $clones->get($characterId);
            $last = $clone?->last_clone_jump_date === null ? null : Carbon::parse($clone->last_clone_jump_date);
            $result[] = [
                'character_id' => (int) $characterId,
                'name' => (string) $name,
                'home' => $clone === null ? '–' : $where($clone->home_location_id),
                'jump_ready' => $last?->copy()->addHours(24 - (int) ($sync[$characterId] ?? 0)),
                'implants' => $typeNames(($implants->get($characterId) ?? collect())->pluck('type_id')),
                'clones' => ($jumpClones->get($characterId) ?? collect())->map(fn ($jump): array => [
                    'name' => (string) ($jump->name ?? ''),
                    'location' => $where($jump->location_id),
                    'implants' => $typeNames((array) $jump->implants),
                ])->sortBy('location')->values()->all(),
            ];
        }

        return $result;
    }
}
