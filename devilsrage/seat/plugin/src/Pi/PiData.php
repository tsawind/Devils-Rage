<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

use DevilsRage\Seat\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Patch 50: reads SeAT's PI tables (filled by SeAT's own character updates) into the arrays
 * PiPlanner works on. Nothing here talks to EVE.
 */
final class PiData
{
    /** Interplanetary Consolidation: one more planet per level. */
    public const SKILL_PLANETS = 2495;

    /** Storage room per kind of pin, in m3. */
    private const CAPACITY = ['launchpad' => 10000, 'storage facility' => 12000, 'command center' => 500];

    /** Patch 59: a customs office holds this much per planet (per pilot). */
    public const CUSTOMS_CAPACITY = 35000;

    /**
     * Patch 59: today's volume of one unit per tier (m3). SeAT's static data may still have the
     * old, twice as big sizes, which would make every storage look twice as full.
     */
    public const VOLUME_BY_TIER = [0 => 0.005, 1 => 0.19, 2 => 0.75, 3 => 3.0, 4 => 50.0];

    /** EVE's group ids for PI commodities by tier. */
    private const GROUP_TIER = [1042 => 1, 1034 => 2, 1040 => 3, 1041 => 4];

    /** @var array<int, array{name: string, tier: int, volume: float}> */
    private array $types = [];

    /**
     * Planets of these characters, each in PiPlanner's shape, grouped by character.
     *
     * @param  list<int>  $characterIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function planets(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }
        $planets = DB::table('character_planets')->whereIn('character_id', $characterIds)->get();
        if ($planets->isEmpty()) {
            return [];
        }
        $planetIds = $planets->pluck('planet_id')->unique()->all();
        $names = DB::table('planets')->whereIn('planet_id', $planetIds)->pluck('name', 'planet_id');

        $key = fn ($row): string => $row->character_id.':'.$row->planet_id;
        $pins = DB::table('character_planet_pins')->whereIn('character_id', $characterIds)->get()->groupBy($key);
        $extractors = DB::table('character_planet_extractors')->whereIn('character_id', $characterIds)->get()->keyBy(fn ($row): string => $row->character_id.':'.$row->pin_id);
        $heads = DB::table('character_planet_heads')
            ->whereIn('character_id', $characterIds)
            ->selectRaw('character_id, extractor_id, count(*) as heads, avg(latitude) as lat, avg(longitude) as lon')
            ->groupBy('character_id', 'extractor_id')
            ->get()
            ->keyBy(fn ($row): string => $row->character_id.':'.$row->extractor_id);
        $routes = DB::table('character_planet_routes')->whereIn('character_id', $characterIds)->get()->groupBy($key);
        $contents = DB::table('character_planet_contents')->whereIn('character_id', $characterIds)->get()->groupBy(fn ($row): string => $row->character_id.':'.$row->pin_id);
        $earlier = $this->storageHistory($characterIds);

        $typeIds = $pins->flatten(1)->pluck('type_id')
            ->merge($extractors->pluck('product_type_id'))
            ->merge($routes->flatten(1)->pluck('content_type_id'))
            ->merge($contents->flatten(1)->pluck('type_id'))
            ->filter()->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $this->loadTypes($typeIds);

        $byCharacter = [];
        foreach ($planets as $planet) {
            $k = $key($planet);
            $planetRoutes = $routes->get($k, collect());
            $result = [
                'character_id' => (int) $planet->character_id,
                'planet_id' => (int) $planet->planet_id,
                'name' => (string) ($names[$planet->planet_id] ?? 'Planet '.$planet->planet_id),
                'type' => (string) $planet->planet_type,
                'level' => (int) $planet->upgrade_level,
                'system_id' => (int) $planet->solar_system_id,
                'last_update' => $planet->last_update ? Carbon::parse($planet->last_update, 'UTC')->getTimestamp() : null,
                'extractors' => [],
                'factories' => [],
                'storage' => [],
                // Patch 63: every pin's kind and every route, for the route checks (PiRoutes).
                'pins' => [],
                'routes' => $planetRoutes->map(fn ($route): array => ['from' => (int) $route->source_pin_id, 'to' => (int) $route->destination_pin_id, 'type' => (int) $route->content_type_id])->values()->all(),
            ];
            foreach ($pins->get($k, collect()) as $pin) {
                $kind = $this->pinKind((int) $pin->type_id);
                $result['pins'][(int) $pin->pin_id] = $kind;
                $pinKey = $planet->character_id.':'.$pin->pin_id;
                if ($kind === 'extractor') {
                    $details = $extractors->get($pinKey);
                    $head = $heads->get($pinKey);
                    $product = (int) ($details->product_type_id ?? 0);
                    $result['extractors'][] = [
                        'pin_id' => (int) $pin->pin_id,
                        'product' => $product,
                        'product_name' => $this->types[$product]['name'] ?? 'Extractor',
                        'cycle_time' => $details?->cycle_time !== null ? (int) $details->cycle_time : null,
                        'qty' => $details?->qty_per_cycle !== null ? (int) $details->qty_per_cycle : null,
                        'heads' => (int) ($head->heads ?? 0),
                        'lat' => (float) ($head->lat ?? 0),
                        'lon' => (float) ($head->lon ?? 0),
                        'install' => $pin->install_time ? Carbon::parse($pin->install_time, 'UTC')->getTimestamp() : null,
                        'expiry' => $pin->expiry_time ? Carbon::parse($pin->expiry_time, 'UTC')->getTimestamp() : null,
                    ];
                } elseif (in_array($kind, ['basic', 'advanced', 'hightech'], true)) {
                    $result['factories'][] = [
                        'pin_id' => (int) $pin->pin_id,
                        'kind' => $kind,
                        'inputs' => $planetRoutes->where('destination_pin_id', $pin->pin_id)->pluck('content_type_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
                        'outputs' => $planetRoutes->where('source_pin_id', $pin->pin_id)->pluck('content_type_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
                    ];
                } elseif (isset(self::CAPACITY[$kind])) {
                    $used = 0.0;
                    $held = [];
                    foreach ($contents->get($pinKey, collect()) as $content) {
                        $typeId = (int) $content->type_id;
                        $used += (float) $content->amount * ($this->types[$typeId]['volume'] ?? 0.0);
                        $held[] = ['type_id' => $typeId, 'name' => $this->types[$typeId]['name'] ?? 'Type '.$typeId, 'tier' => $this->types[$typeId]['tier'] ?? -1, 'amount' => (int) $content->amount];
                    }
                    $result['storage'][] = [
                        'pin_id' => (int) $pin->pin_id,
                        'kind' => $kind,
                        'capacity' => self::CAPACITY[$kind],
                        'used' => $used,
                        'contents' => $held,
                        'previous' => $this->previousContents($earlier[$pinKey] ?? [], $result['last_update']),
                    ];
                }
            }
            $byCharacter[(int) $planet->character_id][] = $result;
        }
        foreach ($byCharacter as &$list) {
            usort($list, fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));
        }

        return $byCharacter;
    }

    /**
     * Patch 59: PI goods sitting in customs offices, per character, from SeAT's asset list.
     * EVE lists items in a customs office under the office's own id, which isn't one of the
     * pilot's items, a station or a structure; anything else with PI goods in it is left out.
     *
     * @param  list<int>  $characterIds
     * @return array<int, list<array{location_id: int, used: float, contents: list<array{type_id: int, name: string, tier: int, amount: int}>}>>
     */
    public function customs(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }
        $rows = DB::table('character_assets as a')
            ->join('invTypes as t', 't.typeID', '=', 'a.type_id')
            ->join('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->whereIn('a.character_id', $characterIds)
            ->whereIn('g.categoryID', [42, 43])
            ->whereIn('a.location_type', ['other', 'item'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('character_assets as c')->whereColumn('c.item_id', 'a.location_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('universe_structures as u')->whereColumn('u.structure_id', 'a.location_id'))
            ->select('a.character_id', 'a.location_id', 'a.type_id', 'a.quantity')
            ->get();
        $this->loadTypes($rows->pluck('type_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        $offices = [];
        foreach ($rows as $row) {
            $typeId = (int) $row->type_id;
            $office = &$offices[(int) $row->character_id][(int) $row->location_id];
            $office ??= ['location_id' => (int) $row->location_id, 'used' => 0.0, 'contents' => []];
            $office['used'] += (int) $row->quantity * ($this->types[$typeId]['volume'] ?? 0.0);
            $office['contents'][] = ['type_id' => $typeId, 'name' => $this->types[$typeId]['name'] ?? 'Type '.$typeId, 'tier' => $this->types[$typeId]['tier'] ?? -1, 'amount' => (int) $row->quantity];
            unset($office);
        }

        return array_map('array_values', $offices);
    }

    /**
     * Patch 59: remembers what every launchpad / storage facility held each time a colony was
     * opened in game (run hourly). An empty store is remembered too (type 0).
     */
    public function snapshotStorage(): int
    {
        $planets = DB::table('character_planets')->whereNotNull('last_update')->get(['character_id', 'planet_id', 'last_update']);
        if ($planets->isEmpty()) {
            return 0;
        }
        $pins = DB::table('character_planet_pins')->get(['character_id', 'planet_id', 'pin_id', 'type_id']);
        $this->loadTypes($pins->pluck('type_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $contents = DB::table('character_planet_contents')->get()->groupBy(fn ($row): string => $row->character_id.':'.$row->pin_id);
        $seen = $planets->keyBy(fn ($row): string => $row->character_id.':'.$row->planet_id);

        $rows = [];
        foreach ($pins as $pin) {
            $planet = $seen->get($pin->character_id.':'.$pin->planet_id);
            if ($planet === null || ! isset(self::CAPACITY[$this->pinKind((int) $pin->type_id)])) {
                continue;
            }
            $base = ['character_id' => $pin->character_id, 'planet_id' => $pin->planet_id, 'pin_id' => $pin->pin_id, 'seen_at' => $planet->last_update];
            $rows[] = $base + ['type_id' => 0, 'amount' => 0];
            foreach ($contents->get($pin->character_id.':'.$pin->pin_id, collect()) as $content) {
                $rows[] = $base + ['type_id' => (int) $content->type_id, 'amount' => (int) $content->amount];
            }
        }
        $added = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $added += DB::table('devilsrage_pi_storage')->insertOrIgnore($chunk);
        }
        DB::table('devilsrage_pi_storage')->where('seen_at', '<', now()->subDays(30))->delete();

        return $added;
    }

    /**
     * Earlier extractor programs on these characters' pins (for "the spot is running out").
     *
     * @param  list<int>  $characterIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function history(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return DB::table('devilsrage_pi_runs')
            ->whereIn('character_id', $characterIds)
            ->whereNotNull('qty_per_cycle')
            ->get()
            ->groupBy('pin_id')
            ->map(fn ($rows) => $rows->map(fn ($row): array => [
                'install' => Carbon::parse($row->install_time, 'UTC')->getTimestamp(),
                'product' => (int) $row->product_type_id,
                'cycle_time' => (int) $row->cycle_time,
                'qty' => (int) $row->qty_per_cycle,
                'heads' => (int) $row->heads,
                'lat' => (float) $row->latitude,
                'lon' => (float) $row->longitude,
            ])->values()->all())
            ->all();
    }

    /**
     * How many planets each character may have (Interplanetary Consolidation + 1).
     *
     * @param  list<int>  $characterIds
     * @return array<int, int>
     */
    public function slots(array $characterIds): array
    {
        if ($characterIds === []) {
            return [];
        }

        return DB::table('character_skills')
            ->whereIn('character_id', $characterIds)
            ->where('skill_id', self::SKILL_PLANETS)
            ->pluck('active_skill_level', 'character_id')
            ->map(fn ($level): int => 1 + (int) $level)
            ->all();
    }

    /**
     * Your characters in login order: your accounts as you ordered them, then the rest by name.
     *
     * @param  list<int>  $characterIds
     * @return array<int, string>  character id => name
     */
    public function loginOrder(int $userId, array $characterIds): array
    {
        $names = DB::table('character_infos')->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $order = [];
        $accounts = Account::query()->where('user_id', $userId)->with('characters')->ordered()->get();
        foreach ($accounts as $account) {
            foreach ($account->characters as $link) {
                $id = (int) $link->character_id;
                if (isset($names[$id]) && ! isset($order[$id])) {
                    $order[$id] = (string) $names[$id];
                }
            }
        }
        $rest = $names->except(array_keys($order))->sort(fn ($a, $b): int => strnatcasecmp((string) $a, (string) $b));
        foreach ($rest as $id => $name) {
            $order[(int) $id] = (string) $name;
        }

        return $order;
    }

    /**
     * Names and tiers of the types seen (for PiPlanner).
     *
     * @return array<int, array{name: string, tier: int}>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * Patch 59: names and tiers of these types (for PI templates).
     *
     * @param  list<int>  $typeIds
     * @return array<int, array{name: string, tier: int, volume: float}>
     */
    public function typesFor(array $typeIds): array
    {
        $this->loadTypes($typeIds);

        return array_intersect_key($this->types, array_flip($typeIds));
    }

    /**
     * Patch 63: type ids by exact name (PI products for the P3 suggestions).
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    public function typeIdsByName(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return DB::table('invTypes')
            ->whereIn('typeName', $names)
            ->pluck('typeID', 'typeName')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Records every extractor program SeAT knows about (run hourly). A program is one
     * install_time on one pin; numbers that arrive later fill in the same row.
     */
    public function snapshot(): int
    {
        $count = 0;
        $heads = DB::table('character_planet_heads')
            ->selectRaw('character_id, extractor_id, count(*) as heads, avg(latitude) as lat, avg(longitude) as lon')
            ->groupBy('character_id', 'extractor_id')
            ->get()
            ->keyBy(fn ($row): string => $row->character_id.':'.$row->extractor_id);
        $rows = DB::table('character_planet_extractors as e')
            ->join('character_planet_pins as p', function ($join): void {
                $join->on('p.character_id', '=', 'e.character_id')->on('p.planet_id', '=', 'e.planet_id')->on('p.pin_id', '=', 'e.pin_id');
            })
            ->whereNotNull('p.install_time')
            ->select('e.character_id', 'e.planet_id', 'e.pin_id', 'e.product_type_id', 'e.cycle_time', 'e.qty_per_cycle', 'p.install_time', 'p.expiry_time')
            ->get();
        $now = now();
        foreach ($rows as $row) {
            $head = $heads->get($row->character_id.':'.$row->pin_id);
            $values = [
                'planet_id' => $row->planet_id,
                'expiry_time' => $row->expiry_time,
                'product_type_id' => $row->product_type_id,
                'cycle_time' => $row->cycle_time,
                'heads' => (int) ($head->heads ?? 0),
                'latitude' => (float) ($head->lat ?? 0),
                'longitude' => (float) ($head->lon ?? 0),
                'updated_at' => $now,
            ];
            if ($row->qty_per_cycle !== null) {
                $values['qty_per_cycle'] = $row->qty_per_cycle;
            }
            $where = ['character_id' => $row->character_id, 'pin_id' => $row->pin_id, 'install_time' => $row->install_time];
            if (DB::table('devilsrage_pi_runs')->where($where)->exists()) {
                DB::table('devilsrage_pi_runs')->where($where)->update($values);
            } else {
                DB::table('devilsrage_pi_runs')->insert($where + $values + ['created_at' => $now]);
                $count++;
            }
        }
        DB::table('devilsrage_pi_runs')->where('install_time', '<', now()->subDays(180))->delete();

        return $count;
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<string, array<string, array<int, int>>>  "character:pin" => seen_at => type id => amount
     */
    private function storageHistory(array $characterIds): array
    {
        $history = [];
        $rows = DB::table('devilsrage_pi_storage')
            ->whereIn('character_id', $characterIds)
            ->where('seen_at', '>=', now()->subDays(30))
            ->get(['character_id', 'pin_id', 'type_id', 'amount', 'seen_at']);
        foreach ($rows as $row) {
            $history[$row->character_id.':'.$row->pin_id][(string) $row->seen_at][(int) $row->type_id] = (int) $row->amount;
        }

        return $history;
    }

    /**
     * The last time before $lastUpdate this store was seen, and what it held then.
     *
     * @param  array<string, array<int, int>>  $snapshots
     * @return array{at: int, amounts: array<int, int>}|null
     */
    private function previousContents(array $snapshots, ?int $lastUpdate): ?array
    {
        $best = null;
        foreach ($snapshots as $seenAt => $amounts) {
            $at = Carbon::parse($seenAt, 'UTC')->getTimestamp();
            if (($lastUpdate === null || $at < $lastUpdate) && ($best === null || $at > $best['at'])) {
                unset($amounts[0]);
                $best = ['at' => $at, 'amounts' => $amounts];
            }
        }

        return $best;
    }

    /** @param  list<int>  $typeIds */
    private function loadTypes(array $typeIds): void
    {
        $missing = array_values(array_diff($typeIds, array_keys($this->types)));
        if ($missing === []) {
            return;
        }
        $rows = DB::table('invTypes as t')
            ->leftJoin('invGroups as g', 'g.groupID', '=', 't.groupID')
            ->whereIn('t.typeID', $missing)
            ->select('t.typeID', 't.typeName', 't.volume', 't.groupID', 'g.categoryID')
            ->get();
        foreach ($rows as $row) {
            $tier = $this->tier((int) $row->categoryID, (int) $row->groupID, (float) $row->volume);
            $this->types[(int) $row->typeID] = [
                'name' => (string) $row->typeName,
                'tier' => $tier,
                'volume' => self::VOLUME_BY_TIER[$tier] ?? (float) $row->volume,
            ];
        }
    }

    /** 0 = raw resource, 1-4 = P1-P4, -1 = not a PI product (a pin, say). */
    private function tier(int $category, int $group, float $volume): int
    {
        if ($category === 42) {
            return 0;
        }
        if ($category !== 43) {
            return -1;
        }
        if (isset(self::GROUP_TIER[$group])) {
            return self::GROUP_TIER[$group];
        }
        foreach ([1 => [0.19, 0.38], 2 => [0.75, 1.5], 3 => [3.0, 6.0], 4 => [50.0, 100.0]] as $tier => $sizes) {
            foreach ($sizes as $size) {
                if (abs($volume - $size) < $size * 0.1) {
                    return $tier;
                }
            }
        }

        return 1;
    }

    /** extractor, basic, advanced, hightech, launchpad, storage facility, command center or other. */
    private function pinKind(int $typeId): string
    {
        return PiTemplate::pinKind($this->types[$typeId]['name'] ?? '');
    }
}
