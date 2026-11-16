<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\SkillPlan;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\Prices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    /** Skills shown on each character's Skills dropdown. */
    public const KEY_SKILLS = ['Capital Ships', 'Jump Drive Operation', 'Jump Drive Calibration', 'Jump Fuel Conservation', 'Caldari Freighter', 'ORE Freighter', 'Amarr Freighter', 'Gallente Freighter', 'Minmatar Freighter',
        'Minmatar Dreadnought', 'Caldari Dreadnought', 'Amarr Dreadnought', 'Gallente Dreadnought', 'Precursor Dreadnought', 'Minmatar Carrier', 'Caldari Carrier', 'Amarr Carrier', 'Gallente Carrier',
        'Minmatar Force Auxiliary', 'Caldari Force Auxiliary', 'Amarr Force Auxiliary', 'Gallente Force Auxiliary', 'Capital Industrial Ships', 'Marauders', 'Capital Projectile Turret', 'Capital Hybrid Turret', 'Capital Energy Turret', 'Capital Remote Armor Repair Systems', 'Capital Shield Emission Systems'];

    /** The wormhole home: Daisy. */
    public const HOME = 'J145735';

    public function json(Request $request, Corporation $corporation, Leadership $leadership): JsonResponse
    {
        /** @var User $me */
        $me = auth()->user();
        // SeAT admins can look at the page as any member sees it (read only: mode() ignores this).
        $isAdmin = method_exists($me, 'isAdmin') ? (bool) $me->isAdmin() : (bool) ($me->admin ?? false);
        $user = $me;
        if ($isAdmin && $request->filled('as')) {
            $user = User::query()->find((int) $request->query('as')) ?? $me;
        }
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
        $divisions = $leader ? range(1, 7) : array_values(array_filter([1, $myDivision]));
        $divisionName = [1 => 'corp'];
        foreach ($people as $p) {
            $divisionName[$p['division']] = $p['main'];
        }

        $columns = ['item_id', 'type_id', 'quantity', 'location_id', 'location_flag', 'is_singleton'];
        // The in-game name players gave a ship or container, to tell which one to load.
        if (Schema::hasColumn('character_assets', 'name') && Schema::hasColumn('corporation_assets', 'name')) {
            $columns[] = 'name';
        }
        $mine = DB::table('character_assets')->whereIn('character_id', $characterIds)->get([...$columns, 'character_id']);
        $corp = collect();
        $all = collect();
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
        $prices = Prices::forTypes(array_map('intval', is_array($typeIds) ? $typeIds : collect($typeIds)->all()));
        // Ship maintenance bay (attribute 908) of the big hulls.
        $smb = DB::table('dgmTypeAttributes')->where('attributeID', 908)->whereIn('typeID', $typeIds)
            ->get(['typeID', 'valueFloat', 'valueInt'])->mapWithKeys(fn ($r) => [$r->typeID => (float) ($r->valueFloat ?? $r->valueInt)]);
        // SeAT's SDE can be older than the game (it still says 1,000,000 m³ for a carrier's bay; it's
        // 2,000,000 now): the big hulls' bay and cargo come from ESI's live type info (cached a week).
        $hullStats = [];
        $bigTypes = $types->filter(fn ($t) => in_array($t->groupName ?? '', self::BIG, true));
        $needStats = [];
        foreach ($bigTypes as $t) {
            $hit = Cache::get('devilsrage.hullstats.'.$t->typeID);
            $hit === null ? $needStats[] = $t : $hullStats[$t->typeID] = $hit;
        }
        if ($needStats !== []) {
            $responses = Http::pool(fn ($pool) => array_map(fn ($t) => $pool->as((string) $t->typeID)->acceptJson()->timeout(8)
                ->withHeaders(['User-Agent' => "Devil's Rage SeAT plugin"])
                ->get('https://esi.evetech.net/latest/universe/types/'.$t->typeID.'/'), $needStats));
            foreach ($needStats as $t) {
                $response = $responses[(string) $t->typeID] ?? null;
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $bay = collect($response->json('dogma_attributes') ?? [])->firstWhere('attribute_id', 908);
                    $stats = ['smb' => (float) ($bay['value'] ?? 0), 'cargo' => (float) ($response->json('capacity') ?? 0)];
                    Cache::put('devilsrage.hullstats.'.$t->typeID, $stats, now()->addDays(7));
                    $hullStats[$t->typeID] = $stats;
                }
            }
        }

        // Walk up to the structure / station / space: corp hangars sit in an office item, which
        // isn't part of the division, so walk through all the corp's assets.
        $allById = $all->keyBy('item_id');
        $roots = [];
        foreach ($rows as $row) {
            $at = $row;
            $guard = 0;
            while ($guard++ < 12) {
                $up = $byItem->get($at->location_id) ?? $allById->get($at->location_id);
                if ($up === null) {
                    break;
                }
                $at = $up;
            }
            $roots[$row->item_id] = (int) $at->location_id;
        }
        [$placeNames, $placeSystems, $systemNames] = $this->places(array_values(array_unique(array_values($roots))));
        // The system the page plans for: Daisy unless another one is picked.
        $homeName = (string) ($request->query('system') ?: self::HOME);
        $home = DB::table('mapDenormalize')->where('itemName', $homeName)->where('groupID', 5)->value('itemID');
        if ($home === null) {
            $homeName = self::HOME;
            $home = DB::table('mapDenormalize')->where('itemName', $homeName)->where('groupID', 5)->value('itemID');
        }
        // Packaged sizes: ships (a repackaged battleship is 50,000 m³, not 470,000), modules (capital
        // modules shrink a lot), containers and deployables. Our SDE has no invVolumes, so ESI's
        // public type info, fetched in parallel for types not seen yet and cached for good.
        $packaged = [];
        $wanted = $types->filter(fn ($t) => in_array((int) $t->categoryID, [2, 6, 7, 22, 32], true));
        $missing = [];
        foreach ($wanted as $t) {
            $hit = Cache::get('devilsrage.packaged.'.$t->typeID);
            if ($hit === null) {
                $missing[] = $t;
            } else {
                $packaged[$t->typeID] = (float) $hit;
            }
        }
        foreach (array_chunk($missing, 40) as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(fn ($t) => $pool->as((string) $t->typeID)->acceptJson()->timeout(8)
                ->withHeaders(['User-Agent' => "Devil's Rage SeAT plugin"])
                ->get('https://esi.evetech.net/latest/universe/types/'.$t->typeID.'/'), $chunk));
            foreach ($chunk as $t) {
                $response = $responses[(string) $t->typeID] ?? null;
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $size = (float) ($response->json('packaged_volume') ?? $t->volume);
                    Cache::forever('devilsrage.packaged.'.$t->typeID, $size);
                    $packaged[$t->typeID] = $size;
                }
            }
        }
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
                'item_id' => (int) $row->item_id,
                'name' => isset($row->name) && $row->name !== '' && $row->name !== ($type->typeName ?? null) ? (string) $row->name : null,
                'holder' => (int) $row->location_id,
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
                // Packaged items take their packaged size (a packaged Vargur is 50,000 m³, not 900,000);
                // assembled ones (fitted, or unpacked in a hangar) their full size until repackaged.
                'm3' => round((! $row->is_singleton ? (float) ($packaged[$row->type_id] ?? $type->volume ?? 0) : (float) ($type->volume ?? 0)) * $qty, 1),
                'packaged_m3' => round((float) ($packaged[$row->type_id] ?? $type->volume ?? 0) * $qty, 1),
                'place' => (string) ($placeNames[$roots[$row->item_id]] ?? $roots[$row->item_id]),
                'home' => $home !== null && (int) ($placeSystems[$roots[$row->item_id]] ?? 0) === (int) $home,
                'in' => $holder !== null ? ($types->get($holder->type_id)->typeName ?? '') : '',
                'flag' => (string) $row->location_flag,
            ];
            $items[] = $item;
            if ($isShip && in_array($type->groupName ?? '', self::BIG, true)) {
                $hulls[] = $item + ['type_id' => (int) $row->type_id, 'cargo' => (float) ($hullStats[$row->type_id]['cargo'] ?? $type->capacity ?? 0), 'smb' => (float) (($hullStats[$row->type_id]['smb'] ?? 0) ?: ($smb[$row->type_id] ?? 0))];
            }
        }
        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);
        // Where things are: ISK per solar system, for picking which system to plan for.
        $bySystem = [];
        foreach ($rows as $row) {
            $sys = (int) ($placeSystems[$roots[$row->item_id]] ?? 0);
            if ($sys > 0) {
                $bySystem[$sys] = ($bySystem[$sys] ?? 0) + (float) ($prices[$row->type_id] ?? 0) * max(1, (int) $row->quantity);
            }
        }
        arsort($bySystem);
        $sysNames = DB::table('mapDenormalize')->where('groupID', 5)->whereIn('itemID', array_keys($bySystem))->pluck('itemName', 'itemID');
        $systems = [];
        foreach (array_slice($bySystem, 0, 25, true) as $sys => $value) {
            $systems[] = ['name' => (string) ($sysNames[$sys] ?? $sys), 'value' => round($value)];
        }
        usort($hulls, fn ($a, $b) => [$a['owner'], $a['group']] <=> [$b['owner'], $b['group']]);

        // Where the member's characters are, what they sit in, and Alpha / Omega (an Alpha's
        // skills run below what they trained: active level < trained level).
        $locations = DB::table('character_locations')->whereIn('character_id', $characterIds)->pluck('solar_system_id', 'character_id');
        $ships = DB::table('character_ships')->leftJoin('invTypes', 'invTypes.typeID', '=', 'character_ships.ship_type_id')
            ->leftJoin('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->whereIn('character_ships.character_id', $characterIds)->get(['character_ships.character_id', 'character_ships.ship_item_id', 'invTypes.typeName', 'invGroups.groupName'])->keyBy('character_id');
        $alphas = DB::table('character_skills')->whereIn('character_id', $characterIds)->whereColumn('active_skill_level', '<', 'trained_skill_level')
            ->distinct()->pluck('character_id')->flip();
        $skilled = DB::table('character_skills')->whereIn('character_id', $characterIds)->distinct()->pluck('character_id')->flip();
        // Which big hulls each character can board: the hull's required skills (SDE attributes
        // 182-184 + levels 277-279) against active levels now, and trained levels (if made Omega).
        // Every ship type the member owns, so the page can list what each character flies now
        // and what needs Omega; only the big hulls count for the "nearly there" list.
        $hullTypes = $rows->filter(fn ($r) => (int) ($types->get($r->type_id)->categoryID ?? 0) === 6)->pluck('type_id')->unique()->values()->all();
        $bigTypes = collect($hulls)->pluck('type_id')->unique()->flip();
        $needs = [];
        foreach (DB::table('dgmTypeAttributes')->whereIn('typeID', $hullTypes)->whereIn('attributeID', [182, 183, 184, 277, 278, 279])->get() as $a) {
            $v = (int) ($a->valueFloat ?? $a->valueInt);
            $slot = [182 => 0, 183 => 1, 184 => 2, 277 => 0, 278 => 1, 279 => 2][$a->attributeID];
            $needs[$a->typeID][$slot][$a->attributeID < 200 ? 'skill' : 'level'] = $v;
        }
        $hullNames = $types->only($hullTypes)->map(fn ($t) => (string) $t->typeName);
        $skills = DB::table('character_skills')->whereIn('character_id', $characterIds)->get(['character_id', 'skill_id', 'active_skill_level', 'trained_skill_level'])->groupBy('character_id');
        // Freighter skills set Charon cargo / Bowhead bay size (+5% a level).
        $freighterSkills = DB::table('invTypes')->whereIn('typeName', ['Caldari Freighter', 'ORE Freighter'])->pluck('typeName', 'typeID');
        // Capital and hauling skills worth seeing at a glance (active / trained levels).
        $keySkills = DB::table('invTypes')->whereIn('typeName', self::KEY_SKILLS)->pluck('typeName', 'typeID');
        $sp = Schema::hasTable('character_info_skills')
            ? DB::table('character_info_skills')->whereIn('character_id', $characterIds)->get(['character_id', 'total_sp', 'unallocated_sp'])->keyBy('character_id') : collect();
        // Training speed of the skill in training now: Alphas train at half speed, so roughly
        // 1,500+ SP an hour means the clone is probably Omega.
        $spRate = collect();
        if (Schema::hasTable('character_skill_queues') && Schema::hasColumn('character_skill_queues', 'training_start_sp')) {
            $spRate = DB::table('character_skill_queues')->whereIn('character_id', $characterIds)->where('queue_position', 0)
                ->whereNotNull('start_date')->whereNotNull('finish_date')->get(['character_id', 'start_date', 'finish_date', 'training_start_sp', 'level_end_sp'])
                ->mapWithKeys(function ($q) {
                    $hours = (strtotime((string) $q->finish_date) - strtotime((string) $q->start_date)) / 3600;

                    return [$q->character_id => $hours > 0.05 ? (int) round(((int) $q->level_end_sp - (int) $q->training_start_sp) / $hours) : null];
                });
        }
        $queueEnds = Schema::hasTable('character_skill_queues')
            ? DB::table('character_skill_queues')->whereIn('character_id', $characterIds)->groupBy('character_id')->selectRaw('character_id, max(finish_date) as ends, count(*) as n')->get()->keyBy('character_id') : collect();
        // EVE accounts (one character per account online at a time) and active clone implants.
        $accounts = AccountCharacter::query()->whereIn('character_id', $characterIds)->with(['account', 'launchGroups'])->get()->keyBy('character_id');
        $activeImplants = DB::table('character_implants')->leftJoin('market_prices', 'market_prices.type_id', '=', 'character_implants.type_id')
            ->whereIn('character_implants.character_id', $characterIds)->groupBy('character_implants.character_id')
            ->selectRaw('character_implants.character_id, count(*) as n, coalesce(sum('.Prices::expression().'), 0) as v')->get()->keyBy('character_id');
        $pilots = [];
        foreach ($names as $id => $name) {
            $mySkills = ($skills->get($id) ?? collect())->keyBy('skill_id');
            $can = [];
            $could = [];
            $near = [];
            foreach ($needs as $typeId => $reqs) {
                $now = true;
                $trained = true;
                foreach ($reqs as $req) {
                    if (! isset($req['skill'])) {
                        continue;
                    }
                    $have = $mySkills->get($req['skill']);
                    $now = $now && $have !== null && (int) $have->active_skill_level >= (int) ($req['level'] ?? 1);
                    $trained = $trained && $have !== null && (int) $have->trained_skill_level >= (int) ($req['level'] ?? 1);
                }
                if ($now) {
                    $can[] = $hullNames[$typeId];
                } elseif ($trained) {
                    $could[] = $hullNames[$typeId];
                } else {
                    // How many skill levels short (only the hull's own required skills).
                    $missing = 0;
                    foreach ($reqs as $req) {
                        if (isset($req['skill'])) {
                            $missing += max(0, (int) ($req['level'] ?? 1) - (int) ($mySkills->get($req['skill'])->trained_skill_level ?? 0));
                        }
                    }
                    if ($missing <= 4 && $bigTypes->has($typeId)) {
                        $near[] = ['type' => $hullNames[$typeId], 'missing' => $missing];
                    }
                }
            }
            $ship = $ships->get($id);
            $pilots[] = [
                'id' => (int) $id,
                'name' => $name,
                'home' => $home !== null && (int) ($locations[$id] ?? 0) === (int) $home,
                'system' => (string) ($systemNames[(int) ($locations[$id] ?? 0)] ?? ''),
                'ship' => (string) ($ship->typeName ?? ''),
                'ship_group' => (string) ($ship->groupName ?? ''),
                'in_big_hull' => in_array($ship->groupName ?? '', self::BIG, true),
                'alpha' => $skilled->has($id) ? $alphas->has($id) : null,
                'ship_item_id' => (int) ($ship->ship_item_id ?? 0),
                'account' => ($a = $accounts->get($id)?->account) ? ['id' => (int) $a->id, 'number' => (int) $a->position, 'name' => (string) $a->nickname, 'omega_until' => $a->omega_until] : null,
                // Roles ticked on Your accounts: HD / Coffins / Can coffin, and the corp's Main / Second / Third.
                'roles' => $accounts->get($id)?->launchGroups->pluck('key')->filter()->values()->all() ?? [],
                'groups' => $accounts->get($id)?->launchGroups->pluck('name')->values()->all() ?? [],
                'implants' => (int) ($activeImplants->get($id)->n ?? 0),
                'implants_value' => round((float) ($activeImplants->get($id)->v ?? 0)),
                'near' => $near,
                'freighter' => collect($freighterSkills)->mapWithKeys(fn ($skillName, $skillId) => [$skillName => (int) ($mySkills->get($skillId)->active_skill_level ?? 0)])->all(),
                'can' => $can,
                'could' => $could,
                'skills' => collect($keySkills)->map(fn ($skillName, $skillId) => ['name' => $skillName, 'active' => (int) ($mySkills->get($skillId)->active_skill_level ?? 0), 'trained' => (int) ($mySkills->get($skillId)->trained_skill_level ?? 0)])
                    ->filter(fn ($k) => $k['trained'] > 0)->values()->all(),
                'sp' => isset($sp[$id]) ? (int) $sp[$id]->total_sp : null,
                'sp_free' => isset($sp[$id]) ? (int) $sp[$id]->unallocated_sp : null,
                'queue_ends' => $queueEnds->get($id)->ends ?? null,
                'queue' => (int) ($queueEnds->get($id)->n ?? 0),
                'sp_rate' => $spRate->get($id),
            ];
        }

        // Skill plans (SeAT's Skill plans page): how far each character is, e.g. the HD doctrines.
        $plans = [];
        foreach (SkillPlan::query()->with('skills')->orderBy('name')->get() as $plan) {
            $plans[] = ['id' => (int) $plan->id, 'name' => (string) $plan->name];
            $progress = app(SkillCheck::class)->compare(array_map('intval', $names->keys()->all()), $plan->requirements());
            foreach ($pilots as &$pilot) {
                $row = $progress[$pilot['id']] ?? null;
                if ($row !== null) {
                    $pilot['plans'][$plan->id] = [
                        'pct' => $row['total_points'] > 0 ? (int) floor(100 * $row['done_points'] / $row['total_points']) : 100,
                        'days' => round($row['seconds'] / 86400, 1),
                        'sp_left' => (int) ($row['missing_points'] ?? max(0, $row['total_points'] - $row['done_points'])),
                    ];
                }
            }
            unset($pilot);
        }

        // Jump clones and what their implants are worth; the ones in Daisy die with the structure.
        $clones = [];
        $cloneRows = DB::table('character_jump_clones')->whereIn('character_id', $characterIds)->get(['character_id', 'name', 'location_id', 'implants']);
        $implantNames = DB::table('invTypes')->whereIn('typeID', $cloneRows->flatMap(fn ($c) => (array) (json_decode((string) $c->implants, true) ?: []))->unique()->values())->pluck('typeName', 'typeID');
        foreach ($cloneRows as $clone) {
            $implants = array_map('intval', (array) (json_decode((string) $clone->implants, true) ?: []));
            [$cName, $cSystem] = [$placeNames[$clone->location_id] ?? null, $placeSystems[$clone->location_id] ?? null];
            if ($cName === null) {
                [$n, $sy] = $this->places([(int) $clone->location_id]);
                $cName = $n[$clone->location_id] ?? (string) $clone->location_id;
                $cSystem = $sy[$clone->location_id] ?? null;
            }
            $clones[] = [
                'character' => (string) ($names[$clone->character_id] ?? $clone->character_id),
                'place' => (string) $cName,
                'home' => $home !== null && (int) $cSystem === (int) $home,
                'name' => (string) ($clone->name ?? ''),
                'implants' => count($implants),
                // Short implant names ("High-grade Amulet Alpha" → "HG Amulet Alpha"), for telling clones apart.
                'implant_names' => array_values(array_map(fn ($id) => str_replace(['High-grade ', 'Mid-grade ', 'Low-grade '], ['HG ', 'MG ', 'LG '], (string) ($implantNames[$id] ?? $id)), $implants)),
                'value' => round(array_sum(Prices::forTypes(array_map('intval', $implants)))),
            ];
        }

        // Plans: the corp's (war / eviction) and vacations.
        $modes = DB::table('devilsrage_asset_modes')->get()->keyBy(fn ($r) => $r->user_id ?? 'corp');
        $corpMode = $modes->get('corp');
        $vacations = [];
        foreach ($people as $p) {
            $v = $modes->get($p['user_id']);
            if ($leader || $p['user_id'] === $user->id) {
                $daisy = collect($items)->where('division', $p['division'])->where('home', true)->sum('value');
                $vacations[] = $p + [
                    'vacation' => $v !== null && $v->mode === 'vacation' ? ['threat' => (int) $v->threat, 'until' => $v->until] : null,
                    'division_in_home' => $daisy,
                ];
            }
        }
        $myMode = $modes->get($user->id);

        return response()->json([
            'generated' => now()->toIso8601String(),
            'home_name' => $homeName,
            // Rough prices for "what it costs to get a coffin pilot": Omega (500 PLEX a month) and Large Skill Injectors.
            'market' => [
                'plex' => Prices::forTypes([44992])[44992] ?? 0.0,
                'injector' => Prices::forTypes([40520])[40520] ?? 0.0,
            ],
            'systems' => $systems,
            'admin' => $isAdmin,
            'viewing_as' => $user->id !== $me->id ? (string) ($names[$user->main_character_id] ?? $user->name) : null,
            'members' => $isAdmin ? User::query()->whereNotNull('main_character_id')->get(['id', 'main_character_id', 'name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) (DB::table('character_infos')->where('character_id', $u->main_character_id)->value('name') ?? $u->name)])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all() : [],
            'leader' => $leader,
            'me' => $people[$user->id]['main'] ?? ($names[$user->main_character_id] ?? ''),
            'characters' => $names->values(),
            'divisions' => $divisions,
            'my_division' => $myDivision,
            'people' => array_values($vacations),
            'corp_mode' => $corpMode !== null ? ['mode' => $corpMode->mode, 'threat' => (int) $corpMode->threat, 'since' => $corpMode->updated_at] : null,
            'my_mode' => $myMode !== null ? ['mode' => $myMode->mode, 'threat' => (int) $myMode->threat, 'until' => $myMode->until] : null,
            'hulls' => $hulls,
            'pilots' => $pilots,
            'clones' => $clones,
            'plans' => $plans,
            'items' => array_slice($items, 0, 8000),
            'count' => count($items),
        ]);
    }

    /**
     * Names and solar systems of top-level locations (structures, stations, space).
     *
     * @param  list<int>  $ids
     * @return array{0: array<int, string>, 1: array<int, int>, 2: array<int, string>}
     */
    private function places(array $ids): array
    {
        $names = [];
        $systems = [];
        foreach (DB::table('universe_structures')->whereIn('structure_id', $ids)->get(['structure_id', 'name', 'solar_system_id']) as $r) {
            $names[(int) $r->structure_id] = (string) $r->name;
            $systems[(int) $r->structure_id] = (int) $r->solar_system_id;
        }
        foreach (DB::table('universe_stations')->whereIn('station_id', $ids)->get(['station_id', 'name', 'system_id']) as $r) {
            $names[(int) $r->station_id] = (string) $r->name;
            $systems[(int) $r->station_id] = (int) $r->system_id;
        }
        // Our own structures SeAT hasn't named.
        foreach (DB::table('corporation_structures')->whereIn('structure_id', $ids)->get(['structure_id', 'system_id']) as $r) {
            $systems[(int) $r->structure_id] ??= (int) $r->system_id;
        }
        foreach (DB::table('mapDenormalize')->whereIn('itemID', $ids)->get(['itemID', 'itemName', 'solarSystemID', 'groupID']) as $r) {
            $names[(int) $r->itemID] ??= (string) $r->itemName;
            $systems[(int) $r->itemID] ??= (int) ((int) $r->groupID === 5 ? $r->itemID : $r->solarSystemID);
        }
        $systemNames = DB::table('mapDenormalize')->where('groupID', 5)->whereIn('itemID', array_values(array_unique($systems)))->pluck('itemName', 'itemID')->all();
        foreach ($ids as $id) {
            if (! isset($names[$id]) && isset($systems[$id])) {
                $names[$id] = ($systemNames[$systems[$id]] ?? 'Unknown').' structure';
            }
        }

        return [$names, $systems, $systemNames + DB::table('mapDenormalize')->where('groupID', 5)->whereIn('itemID', DB::table('character_locations')->pluck('solar_system_id')->unique())->pluck('itemName', 'itemID')->all()];
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
