<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Corporation\CorporationTitle;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * The app's Assets page. Everything the signed-in member's characters own, their own division
 * hangar (the number in their MAIN's title, e.g. "7, Director" → 7) and division 1 (corp
 * general); directors and up get every member's division too. Big hulls (freighters, Bowheads,
 * capitals) are listed apart with their bay sizes, with where the member's characters are now,
 * so the page can tell whether there are enough pilots to sit in them. War / Being evicted are
 * set for the whole corp by a director; Vacation is each member's own, and directors see them.
 */
class AssetsController extends Controller
{
    public const MODES = ['vacation', 'war', 'eviction'];

    /** Ship groups that hold a lot (cargo / maintenance bays) or need a dedicated pilot. */
    public const BIG = ['Freighter', 'Jump Freighter', 'Carrier', 'Supercarrier', 'Dreadnought', 'Lancer Dreadnought', 'Force Auxiliary', 'Titan', 'Capital Industrial Ship'];

    /** The wormhole home: Daisy. */
    public const HOME = 'J145735';

    public function json(Corporation $corporation, Leadership $leadership): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $leader = $leadership->isLeader($user);
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = DB::table('character_infos')->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $corporationId = $corporation->mainId();

        // Each member's division: the number in their main's title.
        $people = [];
        if ($corporationId !== null) {
            $mains = User::query()->whereNotNull('main_character_id')->pluck('id', 'main_character_id');
            $mainNames = DB::table('character_infos')->whereIn('character_id', $mains->keys())->pluck('name', 'character_id');
            $titles = CorporationTitle::query()->where('corporation_id', $corporationId)->with('characters:character_id')->get();
            foreach ($titles as $title) {
                if (! preg_match('/^\s*([1-7])\b/', strip_tags((string) $title->name), $m)) {
                    continue;
                }
                foreach ($title->characters as $character) {
                    $owner = $mains[$character->character_id] ?? null;
                    if ($owner !== null) {
                        $people[(int) $owner] = ['user_id' => (int) $owner, 'main' => (string) ($mainNames[$character->character_id] ?? ''), 'division' => (int) $m[1]];
                    }
                }
            }
        }
        $myDivision = $people[$user->id]['division'] ?? null;
        $divisions = $leader ? array_values(array_unique(array_merge([1], array_column($people, 'division')))) : array_values(array_filter([1, $myDivision]));
        $divisionName = [1 => 'corp'];
        foreach ($people as $p) {
            $divisionName[$p['division']] = $p['main'];
        }

        $columns = ['item_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'is_singleton'];
        $mine = DB::table('character_assets')->whereIn('character_id', $characterIds)->get([...$columns, 'character_id']);
        $corp = collect();
        if ($corporationId !== null && $divisions !== []) {
            $all = DB::table('corporation_assets')->where('corporation_id', $corporationId)->get($columns);
            $children = $all->groupBy('location_id');
            foreach ($divisions as $division) {
                $keep = $all->where('location_flag', "CorpSAG$division")->keyBy('item_id');
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
                foreach ($keep as $row) {
                    $row->division = $division;
                    $corp->push($row);
                }
            }
        }

        $rows = $mine->concat($corp);
        $byItem = $rows->keyBy('item_id');
        $typeIds = $rows->pluck('type_id')->unique()->all();
        $types = DB::table('invTypes')->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->whereIn('invTypes.typeID', $typeIds)
            ->get(['invTypes.typeID', 'invTypes.typeName', 'invTypes.volume', 'invTypes.capacity', 'invGroups.groupName', 'invGroups.categoryID'])->keyBy('typeID');
        $prices = DB::table('market_prices')->whereIn('type_id', $typeIds)->pluck('adjusted_price', 'type_id');
        // Ship maintenance bay (attribute 908) of the big hulls.
        $smb = DB::table('dgmTypeAttributes')->where('attributeID', 908)->whereIn('typeID', $typeIds)
            ->get(['typeID', 'valueFloat', 'valueInt'])->mapWithKeys(fn ($r) => [$r->typeID => (float) ($r->valueFloat ?? $r->valueInt)]);

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
        $hulls = [];
        foreach ($rows as $row) {
            $type = $types->get($row->type_id);
            $holder = $byItem->get($row->location_id);
            $isShip = (int) ($type->categoryID ?? 0) === 6;
            $qty = max(1, (int) $row->quantity);
            $division = $row->division ?? null;
            $owner = $division === null ? (string) ($names[$row->character_id] ?? $row->character_id) : "Division $division (".($divisionName[$division] ?? '?').')';
            $item = [
                'owner' => $owner,
                'mine' => $division === null || $division === $myDivision,
                'division' => $division,
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
            $items[] = $item;
            if ($isShip && in_array($type->groupName ?? '', self::BIG, true)) {
                $hulls[] = $item + ['cargo' => (float) ($type->capacity ?? 0), 'smb' => (float) ($smb[$row->type_id] ?? 0)];
            }
        }
        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);
        usort($hulls, fn ($a, $b) => [$a['owner'], $a['group']] <=> [$b['owner'], $b['group']]);

        // Where the member's characters are, and what they sit in.
        $home = DB::table('mapDenormalize')->where('itemName', self::HOME)->where('groupID', 5)->value('itemID');
        $locations = DB::table('character_locations')->whereIn('character_id', $characterIds)->pluck('solar_system_id', 'character_id');
        $ships = DB::table('character_ships')->leftJoin('invTypes', 'invTypes.typeID', '=', 'character_ships.ship_type_id')
            ->whereIn('character_ships.character_id', $characterIds)->pluck('invTypes.typeName', 'character_ships.character_id');
        $pilots = [];
        foreach ($names as $id => $name) {
            $pilots[] = ['name' => $name, 'home' => $home !== null && (int) ($locations[$id] ?? 0) === (int) $home, 'ship' => (string) ($ships[$id] ?? '')];
        }

        // Plans: the corp's (war / eviction) and vacations.
        $modes = DB::table('devilsrage_asset_modes')->get()->keyBy(fn ($r) => $r->user_id ?? 'corp');
        $corpMode = $modes->get('corp');
        $vacations = [];
        foreach ($people as $p) {
            $v = $modes->get($p['user_id']);
            if ($leader || $p['user_id'] === $user->id) {
                $daisy = collect($items)->where('division', $p['division'])->filter(fn ($i) => str_contains($i['place'], self::HOME))->sum('value');
                $vacations[] = $p + [
                    'vacation' => $v !== null && $v->mode === 'vacation' ? ['threat' => (int) $v->threat, 'until' => $v->until] : null,
                    'division_in_home' => $daisy,
                ];
            }
        }
        $me = $modes->get($user->id);

        return response()->json([
            'generated' => now()->toIso8601String(),
            'leader' => $leader,
            'me' => $people[$user->id]['main'] ?? ($names[$user->main_character_id] ?? ''),
            'characters' => $names->values(),
            'divisions' => $divisions,
            'my_division' => $myDivision,
            'people' => array_values($vacations),
            'corp_mode' => $corpMode !== null ? ['mode' => $corpMode->mode, 'threat' => (int) $corpMode->threat, 'since' => $corpMode->updated_at] : null,
            'my_mode' => $me !== null ? ['mode' => $me->mode, 'threat' => (int) $me->threat, 'until' => $me->until] : null,
            'hulls' => $hulls,
            'pilots' => $pilots,
            'items' => array_slice($items, 0, 8000),
            'count' => count($items),
        ]);
    }

    /** Set or clear a plan: scope "corp" (War / Being evicted, directors) or "me" (Vacation). */
    public function mode(Request $request, Leadership $leadership): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'required|in:corp,me',
            'mode' => 'nullable|in:'.implode(',', self::MODES),
            'threat' => 'nullable|integer|min:1|max:5',
            'days' => 'nullable|integer|min:1|max:120',
        ]);
        $user = auth()->user();
        if ($data['scope'] === 'corp' && ! $leadership->isLeader($user)) {
            return response()->json(['message' => 'Only directors can set War or Being evicted for the corp.'], 403);
        }
        $key = $data['scope'] === 'corp' ? null : (int) $user->id;
        $query = DB::table('devilsrage_asset_modes')->where(fn ($q) => $key === null ? $q->whereNull('user_id') : $q->where('user_id', $key));
        if (($data['mode'] ?? null) === null) {
            $query->delete();

            return response()->json(['message' => 'Cleared.']);
        }
        $row = ['mode' => $data['mode'], 'threat' => (int) ($data['threat'] ?? 2), 'until' => isset($data['days']) ? now()->addDays((int) $data['days']) : null, 'set_by' => $user->id, 'updated_at' => now()];
        if ($query->exists()) {
            $query->update($row);
        } else {
            DB::table('devilsrage_asset_modes')->insert($row + ['user_id' => $key, 'created_at' => now()]);
        }

        return response()->json(['message' => $data['scope'] === 'corp' ? ucfirst($data['mode']).' is on for the whole corp.' : 'Saved.']);
    }
}
