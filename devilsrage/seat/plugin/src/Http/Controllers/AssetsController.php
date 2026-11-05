<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Services\Corporation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Corporation\CorporationTitle;
use Seat\Web\Http\Controllers\Controller;

/**
 * The app's Assets page: everything the signed-in member's characters own, plus their own
 * corp hangar division (the number in their title, e.g. "7, Director" → division 7), every item
 * with where it sits (structure / station, and the ship or container it's in), value, m³ and,
 * for ships, assembled / rigged. Plain JSON; the app keeps a copy on the PC.
 */
class AssetsController extends Controller
{
    public function json(Corporation $corporation): JsonResponse
    {
        $user = auth()->user();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = DB::table('character_infos')->whereIn('character_id', $characterIds)->pluck('name', 'character_id');

        // Division numbers from the member's titles in the main corp ("7, Director" → 7).
        $corporationId = $corporation->mainId();
        $divisions = [];
        if ($corporationId !== null) {
            $titles = CorporationTitle::query()->where('corporation_id', $corporationId)
                ->whereHas('characters', fn ($q) => $q->whereIn('character_infos.character_id', $characterIds))->pluck('name');
            foreach ($titles as $title) {
                if (preg_match('/^\s*([1-7])\b/', strip_tags((string) $title), $m)) {
                    $divisions[] = (int) $m[1];
                }
            }
        }
        $divisions = array_values(array_unique($divisions));

        $columns = ['item_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'is_singleton'];
        $mine = DB::table('character_assets')->whereIn('character_id', $characterIds)->get([...$columns, 'character_id']);
        $corp = collect();
        if ($corporationId !== null && $divisions !== []) {
            $all = DB::table('corporation_assets')->where('corporation_id', $corporationId)->get($columns);
            // Roots in the division's hangar, then everything inside them.
            $keep = $all->filter(fn ($r) => in_array($r->location_flag, array_map(fn ($d) => "CorpSAG$d", $divisions), true))->keyBy('item_id');
            $children = $all->groupBy('location_id');
            $queue = $keep->keys()->all();
            while ($queue !== []) {
                $id = array_pop($queue);
                foreach ($children->get($id, []) as $child) {
                    if (! $keep->has($child->item_id)) {
                        $keep->put($child->item_id, $child);
                        $queue[] = $child->item_id;
                    }
                }
            }
            $corp = $keep->values();
        }

        $rows = $mine->concat($corp);
        $byItem = $rows->keyBy('item_id');
        $types = DB::table('invTypes')->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->whereIn('invTypes.typeID', $rows->pluck('type_id')->unique()->all())
            ->get(['invTypes.typeID', 'invTypes.typeName', 'invTypes.volume', 'invGroups.groupName', 'invGroups.categoryID'])->keyBy('typeID');
        $prices = DB::table('market_prices')->whereIn('type_id', $rows->pluck('type_id')->unique()->all())->pluck('adjusted_price', 'type_id');

        // Root location of every item (station / structure / space), and its direct holder.
        $roots = [];
        foreach ($rows as $row) {
            $at = $row;
            $guard = 0;
            while ($byItem->has($at->location_id) && $guard++ < 12) {
                $at = $byItem->get($at->location_id);
            }
            $roots[$row->item_id] = (int) $at->location_id;
        }
        $places = collect(array_unique(array_values($roots)));
        $placeNames = DB::table('universe_structures')->whereIn('structure_id', $places)->pluck('name', 'structure_id')
            ->union(DB::table('universe_stations')->whereIn('station_id', $places)->pluck('name', 'station_id'))
            ->union(DB::table('mapDenormalize')->whereIn('itemID', $places)->pluck('itemName', 'itemID'));
        $rigged = $rows->filter(fn ($r) => str_starts_with((string) $r->location_flag, 'RigSlot'))->pluck('location_id')->flip();

        $items = [];
        foreach ($rows as $row) {
            $type = $types->get($row->type_id);
            $holder = $byItem->get($row->location_id);
            $isShip = (int) ($type->categoryID ?? 0) === 6;
            $qty = max(1, (int) $row->quantity);
            $items[] = [
                'owner' => isset($row->character_id) ? ($names[$row->character_id] ?? (string) $row->character_id) : 'Division '.implode('/', $divisions),
                'type' => $type->typeName ?? ('Type '.$row->type_id),
                'group' => $type->groupName ?? '',
                'ship' => $isShip,
                'assembled' => $isShip && (bool) $row->is_singleton,
                'rigged' => $rigged->has($row->item_id),
                'qty' => $qty,
                'value' => round((float) ($prices[$row->type_id] ?? 0) * $qty),
                'm3' => round((float) ($type->volume ?? 0) * $qty, 1),
                'place' => (string) ($placeNames[$roots[$row->item_id]] ?? $roots[$row->item_id]),
                'in' => $holder !== null ? ($types->get($holder->type_id)->typeName ?? '') : '',
                'flag' => (string) $row->location_flag,
            ];
        }
        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);

        return response()->json([
            'generated' => now()->toIso8601String(),
            'characters' => $names->values(),
            'divisions' => $divisions,
            'items' => array_slice($items, 0, 5000),
            'count' => count($items),
        ]);
    }
}
