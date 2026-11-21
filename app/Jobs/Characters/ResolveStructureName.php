<?php

declare(strict_types=1);

namespace App\Jobs\Characters;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\StructureName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use NicolasKion\Esi\DTO\Structure;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * 20 Nov: a pilot is docked in a player structure the mapper has no name for: read it from EVE
 * with that pilot's Structure names permission, keep it, and tell the maps.
 */
final class ResolveStructureName implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $character_id, public int $structure_id) {}

    /**
     * Ask for a structure's name, at most once an hour per structure, only for a pilot who
     * granted Structure names, and only when it isn't known yet.
     */
    public static function askFor(?Character $character, ?int $structureId): void
    {
        if (! $character instanceof Character || $structureId === null || $structureId <= 0 || StructureName::nameOf($structureId) !== null) {
            return;
        }
        if (! $character->getEsiTokenWithScope(EsiScope::ReadStructures) instanceof EsiToken) {
            return;
        }
        if (! Cache::add(sprintf('structure-name-asked:%d', $structureId), true, now()->addHour())) {
            return;
        }
        self::dispatch((int) $character->id, $structureId);
    }

    public function handle(Esi $esi): void
    {
        $character = Character::query()->find($this->character_id);
        if (! $character instanceof Character) {
            return;
        }
        try {
            $result = $esi->getStructure($character, $this->structure_id);
        } catch (Throwable) {
            return;
        }
        /** @var mixed $data */
        $data = $result->data;
        if ($result->failed() || ! $data instanceof Structure) {
            return;
        }
        StructureName::query()->updateOrCreate(['id' => $this->structure_id], [
            'name' => $data->name,
            'type_id' => $data->type_id,
            'solarsystem_id' => $data->solar_system_id,
        ]);
        Cache::put(StructureName::cacheKey($this->structure_id), $data->name, now()->addDay());
        DispatchCharacterStatusEvents::broadcastFor([$this->character_id]);
    }
}
