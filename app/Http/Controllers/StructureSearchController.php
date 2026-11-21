<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\Solarsystem;
use App\Models\StructureName;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use NicolasKion\Esi\DTO\SearchResult;
use NicolasKion\Esi\DTO\Structure;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * 20 Nov: "dock <name>" in the search: player structures (citadels, engineering complexes) by
 * name, from the names the mapper already knows plus EVE's own search with one of your pilots
 * who granted Find structures (only structures that pilot can dock at). Picking one sets it as
 * the destination on your online characters.
 */
final class StructureSearchController extends Controller
{
    private const int LIMIT = 10;

    public function index(Request $request, #[CurrentUser] User $user, Esi $esi): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']]);
        $query = trim((string) $validated['q']);

        $searcher = $user->characters()->get()->first(fn (Character $c): bool => $c->getEsiTokenWithScope(EsiScope::SearchStructures) instanceof EsiToken);
        $found = $searcher instanceof Character ? $this->searchEve($esi, $searcher, $query) : [];

        $ids = StructureName::query()
            ->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')
            ->limit(self::LIMIT)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->merge($found)
            ->unique()
            ->take(self::LIMIT)
            ->values()
            ->all();

        $structures = StructureName::query()->whereKey($ids)->orderBy('name')->get();
        $systemNames = Solarsystem::query()->whereKey($structures->pluck('solarsystem_id')->filter()->unique()->all())->pluck('name', 'id');

        return response()->json([
            'searched_eve' => $searcher instanceof Character,
            'structures' => $structures->map(fn (StructureName $s): array => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'solarsystem' => $s->solarsystem_id === null ? null : ($systemNames[$s->solarsystem_id] ?? null),
            ])->values()->all(),
        ]);
    }

    /**
     * EVE's search for structures this pilot can dock at; names read for the ones not known yet.
     *
     * @return list<int>
     */
    private function searchEve(Esi $esi, Character $character, string $query): array
    {
        /** @var list<int> */
        return Cache::remember(sprintf('structure-search:%d:%s', $character->id, mb_strtolower($query)), now()->addMinutes(10), function () use ($esi, $character, $query): array {
            try {
                $result = $esi->search($character, ['structure'], $query);
            } catch (Throwable) {
                return [];
            }
            /** @var mixed $data */
            $data = $result->data;
            if ($result->failed() || ! $data instanceof SearchResult) {
                return [];
            }
            $ids = array_slice(array_map(intval(...), $data->structure), 0, self::LIMIT);
            $canRead = $character->getEsiTokenWithScope(EsiScope::ReadStructures) instanceof EsiToken;
            foreach ($ids as $id) {
                if (! $canRead || StructureName::nameOf($id) !== null) {
                    continue;
                }
                try {
                    $structure = $esi->getStructure($character, $id);
                } catch (Throwable) {
                    continue;
                }
                /** @var mixed $info */
                $info = $structure->data;
                if (! $structure->failed() && $info instanceof Structure) {
                    StructureName::query()->updateOrCreate(['id' => $id], ['name' => $info->name, 'type_id' => $info->type_id, 'solarsystem_id' => $info->solar_system_id]);
                }
            }

            return $ids;
        });
    }
}
