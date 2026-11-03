<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\PiScan as StoredScan;
use DevilsRage\Seat\Models\PiTemplate as StoredTemplate;
use DevilsRage\Seat\Models\Setting;
use DevilsRage\Seat\Pi\PiData;
use DevilsRage\Seat\Pi\PiFeed;
use DevilsRage\Seat\Pi\PiMap;
use DevilsRage\Seat\Pi\PiOptions;
use DevilsRage\Seat\Pi\PiMoney;
use DevilsRage\Seat\Pi\PiDigest;
use DevilsRage\Seat\Pi\PiPlanner;
use DevilsRage\Seat\Pi\PiScan;
use DevilsRage\Seat\Pi\PiSkills;
use DevilsRage\Seat\Pi\PiTemplate;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Services\DiscordDm;
use DevilsRage\Seat\Services\FarmPrices;
use DevilsRage\Seat\Services\JitaPrices;
use DevilsRage\Seat\Services\PersonName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Eveapi\Jobs\PlanetaryInteraction\Character\Planets;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Patch 50: the PI screen. "PI run" says which character to log in, which planet, and what to
 * do there (restart, move heads, balance two raw lines, haul); "My colonies" lists everything;
 * directors also get the corp overview. Data comes from SeAT's own character updates.
 */
class PiController extends Controller
{
    /** Patch 59: the customs tax rate on our offices, in percent (directors set it). */
    public const TAX_SETTING = 'pi_customs_tax';

    public function screen(Request $request, JitaPrices $jita, PiSkills $piSkills): View
    {
        $hub = array_key_exists((string) $request->query('hub'), JitaPrices::HUBS) ? (string) $request->query('hub') : 'jita';
        $otherHub = $hub === 'jita' ? 'amarr' : 'jita';
        $taxPercent = $this->taxPercent();
        $taxRate = $taxPercent / 100;
        $user = auth()->user();
        $ids = array_map('intval', $user->associatedCharacterIds());
        $data = new PiData();
        $planets = $data->planets($ids);
        $now = now()->getTimestamp();
        $planner = new PiPlanner($data->types(), $data->history($ids), $now);
        $order = $data->loginOrder((int) $user->id, $ids);
        $slots = $data->slots($ids);
        $customs = $data->customs(array_map('intval', array_keys($planets)));
        $unplaced = [];
        foreach ($planets as $characterId => &$list) {
            $offices = $planner->placeCustoms($list, $customs[$characterId] ?? [], PiData::CUSTOMS_CAPACITY);
            foreach ($list as &$planet) {
                $planet['customs'] = $offices['placed'][$planet['planet_id']] ?? null;
            }
            unset($planet);
            $unplaced[$characterId] = $offices['unplaced'];
        }
        unset($list);

        $characters = [];
        $colonies = [];
        foreach ($order as $id => $name) {
            $characters[$id] = ['name' => $name, 'planets' => $planets[$id] ?? []];
            if (isset($planets[$id])) {
                $colonies[] = [
                    'character_id' => $id,
                    'name' => $name,
                    'slots' => $slots[$id] ?? null,
                    'planets' => array_map(fn (array $p): array => $planner->planet($p) + ['steps' => []], $planets[$id]),
                ];
            }
        }
        foreach ($colonies as &$colony) {
            foreach ($colony['planets'] as &$info) {
                $info['steps'] = $planner->steps($info);
            }
            unset($info);
            $colony['customs_unplaced'] = $unplaced[$colony['character_id']] ?? [];
        }
        unset($colony);

        $priceIds = [];
        foreach ($colonies as $colony) {
            foreach ($colony['planets'] as $info) {
                foreach ($planner->products($info) as $item) {
                    $priceIds[] = $item['type'];
                }
            }
        }
        $prices = $jita->get($priceIds, $hub);
        $otherPrices = $jita->get($priceIds, $otherHub);
        $totals = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0, 'tax' => 0.0, 'other_split' => 0.0, 'potential' => 0.0, 'pad' => 0.0, 'customs' => 0.0, 'customs_capacity' => 0];
        // Patch 63: what would add the most ISK (PiMoney), gathered as the planets are valued.
        $money = ['stopped' => ['count' => 0, 'per_day' => 0.0], 'short' => ['count' => 0, 'per_day' => 0.0, 'planets' => []], 'hub' => 0.0, 'p2' => [], 'stock' => []];
        foreach ($colonies as &$colony) {
            $sum = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0, 'tax' => 0.0, 'other_split' => 0.0, 'potential' => 0.0, 'pad' => 0.0, 'customs' => 0.0, 'customs_capacity' => count($colony['planets']) * PiData::CUSTOMS_CAPACITY];
            foreach ($colony['planets'] as &$info) {
                $info['value'] = $planner->value($info, $prices, $taxRate);
                $info['value']['other_split'] = $planner->value($info, $otherPrices, $taxRate)['split'];
                // Patch 63: what it makes with its factories fully fed (raw no longer short).
                $info['value']['potential'] = $info['supply'] !== null && $info['supply'] < 0.995 ? $planner->value(['supply' => null] + $info, $prices, $taxRate)['split'] : $info['value']['split'];
                foreach (['buy', 'split', 'sell', 'tax', 'other_split', 'potential'] as $side) {
                    $sum[$side] += $info['value'][$side];
                }
                if ($info['status'] === 'stopped' && $info['value']['split'] > 0) {
                    $money['stopped']['count']++;
                    $money['stopped']['per_day'] += $info['value']['split'];
                }
                $missing = $info['value']['potential'] - $info['value']['split'];
                if ($info['supply'] !== null && $info['supply'] < 0.9 && $missing > 0) {
                    $money['short']['count']++;
                    $money['short']['per_day'] += $missing;
                    $money['short']['planets'][] = ['name' => $colony['name'].' · '.$info['planet']['name'], 'per_day' => $missing];
                }
                $money['hub'] += max(0.0, $info['value']['other_split'] - $info['value']['split']);
                foreach ($info['value']['items'] as $item) {
                    if ($item['tier'] === 2) {
                        $money['p2'][$item['name']] = ($money['p2'][$item['name']] ?? 0) + $item['per_day'];
                    }
                }
                foreach ($info['stores'] as $store) {
                    array_push($money['stock'], ...($store['contents'] ?? []));
                }
                array_push($money['stock'], ...($info['customs']['contents'] ?? []));
                $sum['pad'] += $info['launchpad']['used'] ?? 0.0;
                $sum['customs'] += $info['customs']['used'] ?? 0.0;
            }
            unset($info);
            foreach ($colony['customs_unplaced'] as $office) {
                $sum['customs'] += $office['used'];
                array_push($money['stock'], ...($office['contents'] ?? []));
            }
            $colony['totals'] = $sum;
            foreach ($sum as $key => $value) {
                $totals[$key] += $value;
            }
        }
        unset($colony);

        // Patch 65: factory planets fed with P2 / P3 from your other characters (Tsa hauls the
        // P2 to J145735 IX and V for Organic Mortar Applicators). Worth what they add on top.
        $this->feedFactoryPlanets($colonies, $totals, $money, $data, $planner, $jita, $hub, $otherHub, $prices, $otherPrices, $taxRate);

        // Patch 60: skills for the corp's PI bar, what they'd cost and add (for every character, PI or not).
        $running = 0;
        foreach ($colonies as $colony) {
            foreach ($colony['planets'] as $info) {
                $running += $info['planet']['extractors'] !== [] && $info['value']['split'] > 0 ? 1 : 0;
            }
        }
        $perColony = $running > 0 ? $totals['split'] / $running : 0.0;
        $skillChecks = $piSkills->check(array_keys($order));
        $counts = array_map(fn (array $c): int => count($c['planets']), array_column($colonies, null, 'character_id'));
        $skillRows = [];
        foreach ($order as $characterId => $name) {
            if (! isset($skillChecks[$characterId])) {
                continue;
            }
            $skillRows[$characterId] = $skillChecks[$characterId] + ['name' => $name, 'colonies' => $counts[$characterId] ?? 0]
                + PiSkills::potential($skillChecks[$characterId], $counts[$characterId] ?? 0, $perColony);
        }

        // Patch 65: Alpha characters can't do PI. Keep them out of "more colonies" and show
        // what making their accounts Omega would add instead.
        $alphaIds = $this->alphaCharacterIds((int) $user->id, $colonies, $now);
        $suggestions = $this->moneySuggestions($money, $totals, $skillRows, $perColony, $data, $jita, $hub, JitaPrices::HUBS[$otherHub]['name'], $taxRate, $alphaIds);

        $setting = AccountSetting::query()->find($user->id);
        $leader = Gate::allows('devilsrage-leader');
        $checked = DB::table('character_planets')->whereIn('character_id', $ids)->max('updated_at');

        return view('devilsrage::pi.screen', [
            'run' => $planner->run($characters),
            'colonies' => $colonies,
            'totals' => $totals,
            'skillRows' => $skillRows,
            'suggestions' => $suggestions,
            'perColony' => $perColony,
            'priceSource' => collect($prices)->contains(fn (array $p): bool => ! str_ends_with($p['source'], ' orders')) ? JitaPrices::HUBS[$hub]['name'].' orders, market average where it has none' : JitaPrices::HUBS[$hub]['name'].' orders',
            'hub' => $hub,
            'hubs' => array_map(fn (array $h): string => $h['name'], JitaPrices::HUBS),
            'otherHub' => JitaPrices::HUBS[$otherHub]['name'],
            'taxPercent' => $taxPercent,
            'withoutPi' => count($order) - count($colonies),
            'now' => $now,
            'checked' => $checked ? Carbon::parse($checked, 'UTC')->getTimestamp() : null,
            'alerts' => [
                'mode' => $setting->pi_alerts ?? 'never',
                'hour' => (int) ($setting->pi_alert_hour ?? 18),
                'timezone' => $setting->pi_timezone ?? null,
            ],
            'hasDiscord' => ConnectorUser::query()->where('connector_type', 'discord')->where('user_id', $user->id)->exists(),
            'leader' => $leader,
            'corp' => $leader ? $this->corp($now, $jita, $hub, $taxRate, $piSkills) : null,
            'templates' => $this->templates((int) $user->id, $leader),
            'options' => $this->setupOptions($colonies, $prices, $taxRate),
            'alphaIds' => $alphaIds,
            'corpMap' => $this->corpMap($data, $leader),
        ]);
    }

    /**
     * Patch 65: share your own P2 / P3 among your factory planets, and value each one by what it
     * adds (what it makes minus what the goods it uses would sell for). Adjusts the planet, its
     * character's totals, the grand totals and the P2 left for the "turn into P3" suggestion.
     *
     * @param  list<array<string, mixed>>  $colonies
     * @param  array<string, float|int>  $totals
     * @param  array<string, mixed>  $money
     * @param  array<int, array{buy: float, sell: float}>  $prices
     * @param  array<int, array{buy: float, sell: float}>  $otherPrices
     */
    private function feedFactoryPlanets(array &$colonies, array &$totals, array &$money, PiData $data, PiPlanner $planner, JitaPrices $jita, string $hub, string $otherHub, array &$prices, array &$otherPrices, float $taxRate): void
    {
        $types = $data->types();
        $needs = [];
        foreach ($colonies as $ci => $colony) {
            foreach ($colony['planets'] as $pi => $info) {
                if (($info['value']['split'] ?? 0) <= 0 && ($info['making']['tier'] ?? 0) < 3) {
                    continue;
                }
                $need = PiFeed::imports($info['planet'], $types);
                if ($need !== []) {
                    $needs[$ci.':'.$pi] = $need;
                }
            }
        }
        if ($needs === []) {
            return;
        }
        $supply = [];
        foreach ($colonies as $ci => $colony) {
            foreach ($colony['planets'] as $pi => $info) {
                if (isset($needs[$ci.':'.$pi])) {
                    continue;
                }
                foreach ($info['value']['items'] ?? [] as $item) {
                    if ($item['tier'] >= 1) {
                        $supply[$item['type']] = ($supply[$item['type']] ?? 0) + $item['per_day'] / 24;
                    }
                }
            }
        }
        $inputIds = [];
        foreach ($needs as $need) {
            array_push($inputIds, ...array_keys($need));
        }
        $inputIds = array_values(array_unique($inputIds));
        $prices += $jita->get(array_values(array_diff($inputIds, array_keys($prices))), $hub);
        $otherPrices += $jita->get(array_values(array_diff($inputIds, array_keys($otherPrices))), $otherHub);
        $names = $data->typesFor($inputIds);
        $split = fn (array $list, int $id): float => isset($list[$id]) ? ((float) $list[$id]['buy'] + (float) $list[$id]['sell']) / 2 : 0.0;

        foreach (PiFeed::share($needs, $supply) as $key => $share) {
            [$ci, $pi] = array_map('intval', explode(':', (string) $key));
            $info = &$colonies[$ci]['planets'][$pi];
            $old = $info['value'];
            $supplyNow = $info['supply'] === null ? 1.0 : min(1.0, (float) $info['supply']);
            $fed = min($supplyNow, $share['fed']);
            $value = $planner->value(['supply' => $fed] + $info, $prices, $taxRate);
            $other = $planner->value(['supply' => $fed] + $info, $otherPrices, $taxRate);
            $full = $planner->value(['supply' => null] + $info, $prices, $taxRate);
            $cost = 0.0;
            $otherCost = 0.0;
            $fullCost = 0.0;
            $items = [];
            foreach ($share['items'] as $type => $item) {
                $usedPerDay = $item['need'] * $fed * 24;
                $cost += $usedPerDay * $split($prices, $type);
                $otherCost += $usedPerDay * $split($otherPrices, $type);
                $fullCost += $item['need'] * 24 * $split($prices, $type);
                $items[] = [
                    'name' => (string) ($names[$type]['name'] ?? 'Type '.$type),
                    'need_week' => (int) round($item['need'] * 168),
                    'have_week' => (int) round(min($item['need'], ($supply[$type] ?? 0)) * 168),
                    'made_week' => (int) round(($supply[$type] ?? 0) * 168),
                ];
                $p2Name = $names[$type]['name'] ?? null;
                if ($p2Name !== null && isset($money['p2'][$p2Name])) {
                    $money['p2'][$p2Name] = max(0, $money['p2'][$p2Name] - (int) round($usedPerDay));
                }
            }
            $new = [
                'items' => $value['items'],
                'buy' => $value['buy'] - $cost,
                'split' => $value['split'] - $cost,
                'sell' => $value['sell'] - $cost,
                'tax' => $value['tax'],
                'm3' => $value['m3'],
                'other_split' => $other['split'] - $otherCost,
                'potential' => $full['split'] - $fullCost,
                'feed' => ['fed' => $share['fed'], 'gross' => $value['split'], 'cost' => $cost, 'items' => $items],
            ];
            foreach (['buy', 'split', 'sell', 'tax', 'other_split', 'potential'] as $side) {
                $delta = (float) $new[$side] - (float) ($old[$side] ?? 0);
                $colonies[$ci]['totals'][$side] += $delta;
                $totals[$side] += $delta;
            }
            $info['value'] = $new;
            unset($info);
        }
    }

    /**
     * Patch 65: this pilot's characters on Alpha accounts (they can't do PI). An account counts
     * as Omega when Your accounts says so (Omega until a date still ahead, or on a subscription),
     * or when one of its characters has a running colony. Characters outside any account are left
     * alone (we can't tell).
     *
     * @param  list<array<string, mixed>>  $colonies
     * @return array<int, int>  character id => account id
     */
    private function alphaCharacterIds(int $userId, array $colonies, int $now): array
    {
        $running = [];
        foreach ($colonies as $colony) {
            foreach ($colony['planets'] as $info) {
                foreach ($info['planet']['extractors'] ?? [] as $extractor) {
                    if (($extractor['expiry'] ?? 0) > $now - 8 * 86400) {
                        $running[(int) $colony['character_id']] = true;
                    }
                }
                if (($info['planet']['last_update'] ?? 0) > $now - 8 * 86400) {
                    $running[(int) $colony['character_id']] = true;
                }
            }
        }
        $alpha = [];
        $today = Carbon::createFromTimestamp($now, 'UTC')->startOfDay();
        foreach (Account::query()->where('user_id', $userId)->with('characters')->get() as $account) {
            $ids = $account->characters->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
            $omega = $account->omega_auto || ($account->omega_until !== null && $account->omega_until->greaterThanOrEqualTo($today));
            foreach ($ids as $id) {
                $omega = $omega || isset($running[$id]);
            }
            if (! $omega) {
                foreach ($ids as $id) {
                    $alpha[$id] = (int) $account->id;
                }
            }
        }

        return $alpha;
    }

    /** Patch 65: what one month of Omega costs per day (12-month PLEX bundle at the PLEX price). */
    private function omegaPerDay(): ?float
    {
        // The SP farm's PLEX price: what leaders set there, else SeAT's market average.
        $plex = (float) (Setting::read('farm_price_plex') ?: 0);
        if ($plex <= 0) {
            $plex = (float) (DB::table('market_prices')->where('type_id', 44992)->get(['average', 'average_price'])
                ->map(fn ($row): float => (float) ($row->average > 0 ? $row->average : $row->average_price))
                ->first() ?? 0);
        }

        return $plex > 0 ? app(FarmPrices::class)->omegaPlexPerMonth(12) * $plex / 30 : null;
    }

    /**
     * Patch 64: each pilot's setup options (effort against ISK) for their P0 → P2 planets.
     *
     * @param  list<array<string, mixed>>  $colonies
     * @param  array<int, array{buy: float, sell: float}>  $prices
     * @return array{pilots: list<array{name: string, planets: list<array{name: string, p2: string, options: list<array<string, mixed>>}>, total: list<array<string, mixed>>}>, total: list<array<string, mixed>>}
     */
    private function setupOptions(array $colonies, array $prices, float $taxRate): array
    {
        $pilots = [];
        $everything = [];
        foreach ($colonies as $colony) {
            $planets = [];
            foreach ($colony['planets'] as $info) {
                $making = $info['making'];
                if ($making === null || (int) $making['tier'] !== 2) {
                    continue;
                }
                $lines = [];
                foreach ($info['planet']['extractors'] as $extractor) {
                    if ((int) ($extractor['heads'] ?? 0) > 0 && ! empty($extractor['qty']) && ! empty($extractor['cycle_time'])) {
                        $lines[] = ['qty' => (int) $extractor['qty'], 'cycle' => (int) $extractor['cycle_time'], 'heads' => (int) $extractor['heads']];
                    }
                }
                $splits = [];
                foreach ($making['type_ids'] as $typeId) {
                    $price = $prices[(int) $typeId] ?? null;
                    if ($price !== null) {
                        $splits[] = ((float) $price['buy'] + (float) $price['sell']) / 2;
                    }
                }
                $value = ($splits === [] ? 0.0 : array_sum($splits) / count($splits)) - PiPlanner::exportTax(2, 1, $taxRate);
                $options = PiOptions::forPlanet($lines, ['basic' => (int) $info['factory_counts']['basic'], 'advanced' => (int) $info['factory_counts']['advanced']], max(0.0, $value));
                if ($options === []) {
                    continue;
                }
                $planets[] = ['name' => (string) $info['planet']['name'], 'p2' => implode(', ', $making['names']), 'options' => $options];
                $everything[] = $options;
            }
            if ($planets !== []) {
                $pilots[] = ['name' => (string) $colony['name'], 'planets' => $planets, 'total' => PiOptions::total(array_column($planets, 'options'))];
            }
        }

        return ['pilots' => $pilots, 'total' => PiOptions::total($everything)];
    }

    /**
     * Patch 64: the corp planet map. Every spot the corp has extracted from in the last 180 days,
     * per planet, with its yield and how worked out it is (PiMap). Names of who used a spot are
     * for directors only.
     *
     * @return list<array<string, mixed>>
     */
    private function corpMap(PiData $data, bool $leader): array
    {
        $rows = DB::table('devilsrage_pi_runs')
            ->where('install_time', '>=', now()->subDays(180))
            ->whereNotNull('qty_per_cycle')
            ->where('heads', '>', 0)
            ->get(['character_id', 'planet_id', 'product_type_id', 'heads', 'latitude', 'longitude', 'qty_per_cycle', 'cycle_time', 'install_time']);
        $scans = StoredScan::query()->orderBy('resource')->get(['planet_id', 'resource', 'coverage', 'updated_at'])->groupBy('planet_id');
        if ($rows->isEmpty() && $scans->isEmpty()) {
            return [];
        }
        $planetIds = $rows->pluck('planet_id')->merge($scans->keys())->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $names = DB::table('planets')->whereIn('planet_id', $planetIds)->pluck('name', 'planet_id');
        $kinds = DB::table('character_planets')->whereIn('planet_id', $planetIds)->pluck('planet_type', 'planet_id');
        $radius = [];
        try {
            $radius = DB::table('mapDenormalize')->whereIn('itemID', $planetIds)->pluck('radius', 'itemID')->all();
        } catch (\Throwable) {
            // No SDE map table: distances use a typical planet size (shown as "about").
        }
        $types = $data->typesFor($rows->pluck('product_type_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $who = $leader ? CharacterInfo::query()->whereIn('character_id', $rows->pluck('character_id')->unique()->all())->pluck('name', 'character_id')->all() : [];

        $out = [];
        $byPlanet = $rows->groupBy('planet_id');
        foreach ($planetIds as $planetId) {
            $planetRows = $byPlanet->get($planetId, collect());
            $runs = $planetRows->map(fn ($row): array => [
                'character_id' => (int) $row->character_id,
                'product' => (int) $row->product_type_id,
                'product_name' => (string) ($types[(int) $row->product_type_id]['name'] ?? 'Type '.$row->product_type_id),
                'heads' => (int) $row->heads,
                'lat' => (float) $row->latitude,
                'lon' => (float) $row->longitude,
                'qty' => (int) $row->qty_per_cycle,
                'cycle' => (int) $row->cycle_time,
                'install' => Carbon::parse($row->install_time, 'UTC')->getTimestamp(),
            ])->values()->all();
            $spots = PiMap::spots($runs);
            foreach ($spots as &$spot) {
                $spot['who'] = $leader ? array_values(array_filter(array_map(fn (int $id): ?string => $who[$id] ?? null, $spot['characters']))) : [];
                $spot['pilots'] = count($spot['characters']);
                unset($spot['characters']);
            }
            unset($spot);
            $radiusKm = isset($radius[$planetId]) && (float) $radius[$planetId] > 0 ? (float) $radius[$planetId] / 1000 : null;
            $out[] = [
                'planet_id' => (int) $planetId,
                'name' => (string) ($names[$planetId] ?? 'Planet '.$planetId),
                'type' => (string) ($kinds[$planetId] ?? ''),
                'radius_km' => $radiusKm,
                'spots' => $spots,
                // Patch 71: the app's video scans of this planet (cells fetched when picked).
                'scans' => $scans->get($planetId, collect())->map(fn (StoredScan $scan): array => [
                    'resource' => $scan->resource,
                    'coverage' => round($scan->coverage, 3),
                    'at' => $scan->updated_at?->getTimestamp(),
                ])->values()->all(),
            ];
        }
        usort($out, fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Patch 63: the "Make more ISK" list for the PI run.
     *
     * @param  array<string, mixed>  $money
     * @param  array<string, float|int>  $totals
     * @param  array<int, array<string, mixed>>  $skillRows
     * @return list<array{kind: string, title: string, why: string, per_month: float, now: float}>
     */
    private function moneySuggestions(array $money, array $totals, array $skillRows, float $perColony, PiData $data, JitaPrices $jita, string $hub, string $otherHubName, float $taxRate, array $alphaIds = []): array
    {
        $isk = fn (float $v): string => match (true) {
            abs($v) >= 1e9 => number_format($v / 1e9, 2).' b',
            abs($v) >= 1e6 => number_format($v / 1e6, 1).' m',
            abs($v) >= 1e3 => number_format($v / 1e3, 0).' k',
            default => number_format($v, 0),
        };

        $stockIds = array_values(array_unique(array_map(fn (array $c): int => (int) ($c['type_id'] ?? 0), $money['stock'])));
        $stockPrices = $jita->get(array_values(array_filter($stockIds)), $hub);
        $stock = ['value' => 0.0, 'm3' => 0.0];
        $volumes = $data->typesFor(array_values(array_filter($stockIds)));
        foreach ($money['stock'] as $content) {
            $id = (int) ($content['type_id'] ?? 0);
            $price = $stockPrices[$id] ?? null;
            if ($price !== null) {
                $stock['value'] += (int) $content['amount'] * (((float) $price['buy'] + (float) $price['sell']) / 2);
            }
            $stock['m3'] += (int) $content['amount'] * (float) ($volumes[$id]['volume'] ?? 0);
        }

        $names = PiMoney::recipeNames();
        $ids = $data->typeIdsByName($names);
        $recipePrices = $jita->get(array_values($ids), $hub);
        $byName = [];
        foreach ($ids as $name => $id) {
            if (isset($recipePrices[$id])) {
                $byName[$name] = ((float) $recipePrices[$id]['buy'] + (float) $recipePrices[$id]['sell']) / 2;
            }
        }
        $upgrade = PiMoney::upgrades($money['p2'], $byName, $taxRate)[0] ?? null;

        $grow = ['planets' => 0, 'per_day' => 0.0, 'characters' => 0];
        $omega = ['planets' => 0, 'per_day' => 0.0, 'characters' => 0, 'accounts' => count(array_unique($alphaIds)), 'cost_per_day' => 0.0];
        foreach ($skillRows as $characterId => $row) {
            if (isset($alphaIds[$characterId])) {
                // Omega lets them use their skills again: count what their planets could make.
                $planets = (int) ($row['planets_after'] ?? 0);
                if ($planets > 0) {
                    $omega['planets'] += $planets;
                    $omega['per_day'] += $planets * $perColony;
                    $omega['characters']++;
                }

                continue;
            }
            if (($row['extra_planets'] ?? 0) > 0) {
                $grow['planets'] += (int) $row['extra_planets'];
                $grow['per_day'] += (float) $row['per_day'];
                $grow['characters']++;
            }
        }

        $planets = $money['short']['planets'];
        usort($planets, fn (array $a, array $b): int => $b['per_day'] <=> $a['per_day']);
        $split = (float) $totals['split'];

        return PiMoney::suggestions([
            'stopped' => $money['stopped'],
            'short' => ['count' => $money['short']['count'], 'per_day' => $money['short']['per_day'], 'planets' => $planets],
            'hub' => ['name' => $otherHubName, 'per_day' => $money['hub'], 'share' => $split > 0 ? $money['hub'] / $split : 0.0],
            'stock' => $stock,
            'upgrade' => $upgrade,
            'grow' => $grow,
            'omega' => ['cost_per_day' => ($perDay = $this->omegaPerDay()) === null ? null : $omega['accounts'] * $perDay] + $omega,
        ], $isk);
    }

    /**
     * The opt-in daily message: never (default), when a run is due, or only when something stopped.
     */
    public function saveAlerts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(PiDigest::MODES)],
            'hour' => ['required', 'integer', 'between:0,23'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);
        $setting = AccountSetting::query()->firstOrNew(['user_id' => auth()->id()]);
        $setting->fill(['pi_alerts' => $data['mode'], 'pi_alert_hour' => (int) $data['hour'], 'pi_timezone' => $data['timezone'] ?? $setting->pi_timezone]);
        $setting->save();

        return response()->json(['saved' => true]);
    }

    /**
     * Patch 53: "Update now": ask EVE for this pilot's colonies right away (all their own characters,
     * at most once every 10 minutes: EVE itself only refreshes them that often). Storage and
     * factories still only change when the colony was opened in game.
     */
    public function forceUpdate(): JsonResponse
    {
        $user = auth()->user();
        $key = 'devilsrage:pi:force:'.$user->id;
        if (! Cache::add($key, true, now()->addMinutes(10))) {
            return response()->json(['queued' => 0, 'message' => 'Asked less than 10 minutes ago. EVE refreshes colonies about every 10 minutes.']);
        }
        $ids = array_map('intval', $user->associatedCharacterIds());
        $queued = 0;
        foreach (RefreshToken::query()->whereIn('character_id', $ids)->get() as $token) {
            try {
                Planets::dispatch($token);
                $queued++;
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json(['queued' => $queued, 'message' => sprintf('Asked EVE for %d character%s. Give it a minute, then reload.', $queued, $queued === 1 ? '' : 's')]);
    }

    /**
     * Patch 59: keep a colony template (pasted from EVE). Shared with everyone on our SeAT unless
     * "shared" is off. The same template twice is kept once.
     */
    public function saveTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.PiTemplate::MAX_BYTES],
            'name' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
            'shared' => ['boolean'],
        ]);
        try {
            $parsed = PiTemplate::parse($data['body']);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        $userId = (int) auth()->id();
        $fingerprint = PiTemplate::fingerprint($parsed);
        $existing = StoredTemplate::query()->where('user_id', $userId)->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return response()->json(['id' => $existing->id, 'message' => 'You already have this one: '.$existing->name.'.']);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $summary = PiTemplate::summary($parsed, (new PiData())->typesFor(PiTemplate::typeIds($parsed)));
            $name = mb_substr($summary['title'], 0, 80);
        }
        $template = StoredTemplate::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'body' => trim($data['body']),
            'fingerprint' => $fingerprint,
            'planet_type_id' => (int) $parsed['Pln'],
            'shared' => (bool) ($data['shared'] ?? true),
        ]);

        return response()->json(['id' => $template->id, 'message' => 'Saved.']);
    }

    /** Patch 59: rename, change the note, or share / stop sharing one of your templates. */
    public function updateTemplate(Request $request, int $template): JsonResponse
    {
        $stored = StoredTemplate::query()->where('user_id', auth()->id())->findOrFail($template);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'shared' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('note', $data)) {
            $data['note'] = trim((string) $data['note']) ?: null;
        }
        $stored->fill($data)->save();

        return response()->json(['saved' => true]);
    }

    /** Patch 59: delete a template (your own; directors can remove any shared one). */
    public function deleteTemplate(int $template): JsonResponse
    {
        $stored = StoredTemplate::query()->findOrFail($template);
        if ($stored->user_id !== (int) auth()->id() && ! Gate::allows('devilsrage-leader')) {
            abort(403);
        }
        $stored->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Patch 71: what the app's video scan needs: your colonies (planet, type, radius) with every
     * building's position (the anchors that tell the scan which way the planet faces), and the
     * corp's scans of those planets so far.
     */
    public function scanPlanets(): JsonResponse
    {
        $characterIds = Account::query()->where('user_id', auth()->id())
            ->with('characters')->get()
            ->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $owned = RefreshToken::query()->where('user_id', auth()->id())->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
        $characterIds = array_values(array_unique(array_merge($characterIds, $owned)));
        $mine = DB::table('character_planets')->whereIn('character_id', $characterIds)->get(['character_id', 'planet_id', 'solar_system_id']);
        if ($mine->isEmpty()) {
            return response()->json(['planets' => []]);
        }
        // Patch 73: every corp colony in the systems you have colonies in (anyone's buildings
        // can anchor a scan: EVE shows them with "Show other characters' networks").
        $systems = $mine->pluck('solar_system_id')->unique()->all();
        $planets = DB::table('character_planets')->whereIn('solar_system_id', $systems)->get(['character_id', 'planet_id', 'planet_type']);
        $planetIds = $planets->pluck('planet_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $allChars = $planets->pluck('character_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $names = DB::table('planets')->whereIn('planet_id', $planetIds)->pluck('name', 'planet_id');
        $who = CharacterInfo::query()->whereIn('character_id', $allChars)->pluck('name', 'character_id');
        $radius = [];
        try {
            $radius = DB::table('mapDenormalize')->whereIn('itemID', $planetIds)->pluck('radius', 'itemID')->all();
        } catch (\Throwable) {
            // No SDE map table: the scan doesn't need the size, only the corp map's distances do.
        }
        $pins = DB::table('character_planet_pins')->whereIn('planet_id', $planetIds)->whereIn('character_id', $allChars)
            ->get(['character_id', 'planet_id', 'pin_id', 'type_id', 'latitude', 'longitude'])
            ->groupBy('planet_id');
        // Extractor heads spread wider than the buildings: better anchors for the scan.
        $heads = DB::table('character_planet_heads')->whereIn('character_id', $allChars)
            ->get(['character_id', 'extractor_id', 'head_id', 'latitude', 'longitude'])
            ->groupBy(fn ($row): string => $row->character_id.':'.$row->extractor_id);
        $types = (new PiData())->typesFor($pins->flatten(1)->pluck('type_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $scans = StoredScan::query()->whereIn('planet_id', $planetIds)->get(['planet_id', 'resource', 'coverage', 'updated_at'])->groupBy('planet_id');

        $out = [];
        foreach ($planets->groupBy('planet_id') as $planetId => $colonies) {
            $own = $colonies->first(fn ($row): bool => in_array((int) $row->character_id, $characterIds, true));
            $out[] = [
                'planet_id' => (int) $planetId,
                'character_id' => $own !== null ? (int) $own->character_id : null,
                'character' => $own !== null ? (string) ($who[$own->character_id] ?? '') : '',
                'characters' => $colonies->map(fn ($row): array => [
                    'id' => (int) $row->character_id,
                    'name' => (string) ($who[$row->character_id] ?? ''),
                    'mine' => in_array((int) $row->character_id, $characterIds, true),
                ])->values()->all(),
                'name' => (string) ($names[$planetId] ?? 'Planet '.$planetId),
                'type' => (string) $colonies->first()->planet_type,
                'radius_km' => isset($radius[$planetId]) ? (float) $radius[$planetId] / 1000 : null,
                'pins' => $pins->get($planetId, collect())->map(fn ($pin): array => [
                    'id' => (int) $pin->pin_id,
                    'name' => (string) ($types[(int) $pin->type_id]['name'] ?? 'Building'),
                    'owner' => (string) ($who[$pin->character_id] ?? ''),
                    'lat' => (float) $pin->latitude,
                    'lon' => (float) $pin->longitude,
                    'heads' => $heads->get($pin->character_id.':'.$pin->pin_id, collect())->map(fn ($head): array => [
                        'id' => (int) $head->head_id,
                        'lat' => (float) $head->latitude,
                        'lon' => (float) $head->longitude,
                    ])->values()->all(),
                ])->values()->all(),
                'scans' => $scans->get($planetId, collect())->map(fn (StoredScan $scan): array => [
                    'resource' => $scan->resource,
                    'coverage' => round($scan->coverage, 3),
                    'at' => $scan->updated_at?->getTimestamp(),
                ])->values()->all(),
            ];
        }
        usort($out, fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));

        return response()->json(['planets' => $out]);
    }

    /** Patch 71: one scan's cells (base64), for the app to carry on a scan or show it. */
    public function scanCells(int $planet, string $resource): JsonResponse
    {
        $scan = StoredScan::query()->where('planet_id', $planet)->where('resource', $resource)->firstOrFail();

        return response()->json(['resource' => $scan->resource, 'coverage' => $scan->coverage, 'cells' => base64_encode($scan->cells)]);
    }

    /** Patch 71: the app hands in a scan; it merges into what the corp has for that planet and resource. */
    public function saveScan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'planet_id' => ['required', 'integer', 'min:1'],
            'resource' => ['required', 'string', 'max:60'],
            'character_id' => ['nullable', 'integer'],
            'cells' => ['required', 'string', 'max:100000'],
        ]);
        $cells = PiScan::decode($data['cells']);
        if ($cells === null) {
            return response()->json(['message' => 'That scan is not a whole planet map.'], 422);
        }
        $planetId = (int) $data['planet_id'];
        if (! DB::table('character_planets')->where('planet_id', $planetId)->exists()) {
            return response()->json(['message' => 'Nobody in the corp has a colony on that planet.'], 422);
        }
        $existing = StoredScan::query()->where('planet_id', $planetId)->where('resource', $data['resource'])->first();
        $merged = PiScan::merge($existing?->cells, $cells);
        $coverage = PiScan::coverage($merged);
        StoredScan::query()->updateOrCreate(
            ['planet_id' => $planetId, 'resource' => $data['resource']],
            ['user_id' => (int) auth()->id(), 'character_id' => $data['character_id'] ?? null, 'cells' => $merged, 'coverage' => $coverage],
        );

        return response()->json(['coverage' => round($coverage, 3), 'message' => sprintf('Saved: %d%% of the planet scanned for %s.', (int) round($coverage * 100), $data['resource'])]);
    }

    /** Patch 71: remove a scan (whoever made it last, or a director). */
    public function deleteScan(int $planet, string $resource): JsonResponse
    {
        $scan = StoredScan::query()->where('planet_id', $planet)->where('resource', $resource)->firstOrFail();
        if ($scan->user_id !== (int) auth()->id() && ! Gate::allows('devilsrage-leader')) {
            abort(403);
        }
        $scan->delete();

        return response()->json(['deleted' => true]);
    }

    /** Patch 59: directors set the customs tax on our offices (0-100 %), used in every worth figure. */
    public function saveTax(Request $request): JsonResponse
    {
        if (! Gate::allows('devilsrage-leader')) {
            abort(403);
        }
        $data = $request->validate(['percent' => ['required', 'numeric', 'between:0,100']]);
        Setting::write(self::TAX_SETTING, (string) round((float) $data['percent'], 2));

        return response()->json(['saved' => true]);
    }

    private function taxPercent(): float
    {
        return max(0.0, min(100.0, (float) Setting::read(self::TAX_SETTING, '0')));
    }

    /** "Send me a test": today's message right now, whatever it says. */
    public function testAlert(PiDigest $digest, DiscordDm $dm): JsonResponse
    {
        $user = auth()->user();
        $setting = AccountSetting::query()->firstOrNew(['user_id' => $user->id]);
        $message = $digest->message($user, $setting, true) ?? '';

        $problem = $dm->trySend((int) $user->id, '(test) '.$message);
        $why = match (true) {
            $problem === null => null,
            $problem === 'not_linked' => 'Your Discord isn\'t linked to SeAT yet: SeAT → Connector → Identities, link Discord, then try again.',
            $problem === 'dms_closed' => 'Discord won\'t let the bot DM you: in the corp Discord server, Privacy Settings → turn on Direct Messages (and be on that server).',
            $problem === 'no_bot' => 'The corp bot isn\'t set up in SeAT (Connector → Settings). Ask a director.',
            default => 'Discord said no ('.$problem.'). Try again in a minute.',
        };

        return response()->json(['sent' => $problem === null, 'why' => $why]);
    }

    /**
     * Patch 59: your templates and everyone else's shared ones, each described.
     *
     * @return array{mine: list<array<string, mixed>>, corp: list<array<string, mixed>>}
     */
    private function templates(int $userId, bool $leader): array
    {
        $rows = StoredTemplate::query()
            ->where(fn ($query) => $query->where('user_id', $userId)->orWhere('shared', true))
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();
        $parsed = [];
        $typeIds = [];
        foreach ($rows as $row) {
            try {
                $parsed[$row->id] = PiTemplate::parse($row->body);
                $typeIds = array_merge($typeIds, PiTemplate::typeIds($parsed[$row->id]));
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        $types = (new PiData())->typesFor(array_values(array_unique($typeIds)));
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->values())->with('main_character')->get()->keyBy('id');

        $mine = [];
        $corp = [];
        foreach ($rows as $row) {
            if (! isset($parsed[$row->id])) {
                continue;
            }
            $item = [
                'id' => $row->id,
                'name' => $row->name,
                'note' => $row->note,
                'body' => $row->body,
                'shared' => $row->shared,
                'by' => $users->has($row->user_id) ? PersonName::mainName($users[$row->user_id]) : '?',
                'updated' => $row->updated_at?->getTimestamp(),
                'can_delete' => $row->user_id === $userId || $leader,
                'summary' => PiTemplate::summary($parsed[$row->id], $types),
            ];
            if ($row->user_id === $userId) {
                $mine[] = $item;
            } else {
                $corp[] = $item;
            }
        }
        usort($corp, fn (array $a, array $b): int => [$a['summary']['planet'], $a['name']] <=> [$b['summary']['planet'], $b['name']]);

        return ['mine' => $mine, 'corp' => $corp];
    }

    /**
     * Directors: every corp member's colonies, what needs attention, what the corp makes.
     *
     * @return array<string, mixed>
     */
    private function corp(int $now, JitaPrices $jita, string $hub, float $taxRate, PiSkills $piSkills): array
    {
        $corporation = app(Corporation::class);
        $memberIds = $corporation->memberCharacterIds();
        $mainCorpId = (int) (DB::table('character_affiliations')->whereIn('character_id', $memberIds)->value('corporation_id') ?? 0);
        // Patch 59: every character of every member (alts in NPC or other corps too), by person.
        $userIds = RefreshToken::query()->whereIn('character_id', $memberIds)->pluck('user_id')->unique()->values()->all();
        $owners = RefreshToken::query()->whereIn('user_id', $userIds)->pluck('user_id', 'character_id');
        $ids = array_map('intval', $owners->keys()->all());
        $data = new PiData();
        $planets = $data->planets($ids);
        $planner = new PiPlanner($data->types(), [], $now);
        $slots = $data->slots($ids);
        $users = User::query()->whereIn('id', $userIds)->with('main_character')->get()->keyBy('id');
        $names = DB::table('character_infos')->whereIn('character_id', $ids)->pluck('name', 'character_id');
        $corpOf = DB::table('character_affiliations')->whereIn('character_id', $ids)->pluck('corporation_id', 'character_id');
        $corpIds = $corpOf->unique()->values()->all();
        $corpNames = DB::table('universe_names')->whereIn('entity_id', $corpIds)->pluck('name', 'entity_id')
            ->union(DB::table('corporation_infos')->whereIn('corporation_id', $corpIds)->pluck('name', 'corporation_id'));

        $attention = [];
        $products = [];
        $characterItems = [];
        $systems = [];
        $people = [];
        $colonies = 0;
        $stopped = 0;
        $soon = 0;
        $unused = 0;
        foreach ($ids as $characterId) {
            $userId = (int) ($owners[$characterId] ?? 0);
            $user = $users->get($userId);
            $pilot = $user !== null ? PersonName::of($user) : '?';
            $main = $user !== null ? PersonName::mainName($user) : '?';
            $list = $planets[$characterId] ?? [];
            $corpId = (int) ($corpOf[$characterId] ?? 0);
            $row = [
                'id' => $characterId,
                'name' => (string) ($names[$characterId] ?? $characterId),
                'colonies' => count($list),
                'slots' => $slots[$characterId] ?? null,
                'stopped' => 0,
                'soon' => 0,
                'stopped_since' => null,
                'next_stop' => null,
                'split' => 0.0,
            ];
            $colonies += count($list);
            if ($list !== [] && isset($slots[$characterId])) {
                $unused += max(0, $slots[$characterId] - count($list));
            }
            foreach ($list as $planet) {
                $info = $planner->planet($planet);
                $systems[$planet['system_id']] = ($systems[$planet['system_id']] ?? 0) + 1;
                if ($info['status'] === 'stopped') {
                    $stopped++;
                    $row['stopped']++;
                    $since = $info['stopped_since'] ?? $now;
                    $row['stopped_since'] = $row['stopped_since'] === null ? $since : min($row['stopped_since'], $since);
                } elseif ($info['status'] === 'soon') {
                    $soon++;
                    $row['soon']++;
                }
                if ($info['next_stop'] !== null && $info['next_stop'] > $now) {
                    $row['next_stop'] = $row['next_stop'] === null ? $info['next_stop'] : min($row['next_stop'], $info['next_stop']);
                }
                if (in_array($info['status'], ['stopped', 'soon'], true)) {
                    $attention[] = [
                        'pilot' => $pilot,
                        'character' => $row['name'],
                        'planet' => $planet['name'],
                        'status' => $info['status'],
                        'time' => $info['status'] === 'stopped' ? $info['stopped_since'] : $info['next_stop'],
                        'last_update' => $planet['last_update'],
                    ];
                }
                if ($info['making'] !== null || $planet['factories'] === []) {
                    foreach ($planner->products($info) as $item) {
                        $products[$item['name']] = ['type' => $item['type'], 'tier' => $item['tier'], 'per_day' => ($products[$item['name']]['per_day'] ?? 0) + $item['per_day']];
                        $characterItems[$characterId][$item['type']] = ($characterItems[$characterId][$item['type']] ?? 0) + $item['per_day'];
                    }
                }
            }
            $person = &$people[$userId];
            $person ??= ['name' => $pilot, 'main' => $main, 'characters' => 0, 'pi_characters' => 0, 'colonies' => 0, 'stopped' => 0, 'soon' => 0, 'split' => 0.0, 'corps' => []];
            $person['characters']++;
            $person['pi_characters'] += $list === [] ? 0 : 1;
            $person['colonies'] += count($list);
            $person['stopped'] += $row['stopped'];
            $person['soon'] += $row['soon'];
            $person['corps'][$corpId] ??= ['name' => $corpId === 0 ? 'Unknown corporation' : (string) ($corpNames[$corpId] ?? 'Corporation '.$corpId), 'main' => $corpId === $mainCorpId, 'characters' => []];
            $person['corps'][$corpId]['characters'][] = $row;
            unset($person);
        }

        $prices = $jita->get(array_column($products, 'type'), $hub);
        $tiers = array_column($products, 'tier', 'type');
        $value = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0, 'tax' => 0.0];
        foreach ($products as $name => $product) {
            $tax = PiPlanner::exportTax($product['tier'], $product['per_day'], $taxRate);
            $buy = $product['per_day'] * (float) ($prices[$product['type']]['buy'] ?? 0) - $tax;
            $sell = $product['per_day'] * (float) ($prices[$product['type']]['sell'] ?? 0) - $tax;
            $products[$name] += ['buy' => $buy, 'split' => ($buy + $sell) / 2, 'sell' => $sell];
            $value['buy'] += $buy;
            $value['sell'] += $sell;
            $value['tax'] += $tax;
        }
        $value['split'] = ($value['buy'] + $value['sell']) / 2;
        $splitOf = function (int $characterId) use ($characterItems, $prices, $tiers, $taxRate): float {
            $split = 0.0;
            foreach ($characterItems[$characterId] ?? [] as $typeId => $perDay) {
                $split += $perDay * ((float) ($prices[$typeId]['buy'] ?? 0) + (float) ($prices[$typeId]['sell'] ?? 0)) / 2
                    - PiPlanner::exportTax((int) ($tiers[$typeId] ?? 0), $perDay, $taxRate);
            }

            return $split;
        };
        $running = 0;
        foreach ($planets as $list) {
            foreach ($list as $planet) {
                $running += $planet['extractors'] !== [] ? 1 : 0;
            }
        }
        $perColony = $running > 0 ? $value['split'] / $running : 0.0;
        $skillChecks = $piSkills->check($ids);
        foreach ($people as &$person) {
            $person['could_add'] = 0.0;
            foreach ($person['corps'] as &$corp) {
                foreach ($corp['characters'] as &$row) {
                    $row['split'] = $splitOf($row['id']);
                    $person['split'] += $row['split'];
                    $row['skill'] = isset($skillChecks[$row['id']]) ? $skillChecks[$row['id']] + PiSkills::potential($skillChecks[$row['id']], $row['colonies'], $perColony) : null;
                    $person['could_add'] += $row['skill']['per_day'] ?? 0.0;
                }
                unset($row);
                usort($corp['characters'], fn (array $a, array $b): int => [$b['colonies'] > 0, $a['name']] <=> [$a['colonies'] > 0, $b['name']]);
            }
            unset($corp);
            uasort($person['corps'], fn (array $a, array $b): int => [! $a['main'], $a['name']] <=> [! $b['main'], $b['name']]);
            $person['corps'] = array_values($person['corps']);
        }
        unset($person);
        $people = array_values($people);
        usort($people, fn (array $a, array $b): int => [$b['split'], $a['name']] <=> [$a['split'], $b['name']]);

        usort($attention, fn (array $a, array $b): int => [$a['status'] === 'stopped' ? 0 : 1, $a['time'] ?? 0] <=> [$b['status'] === 'stopped' ? 0 : 1, $b['time'] ?? 0]);
        uasort($products, fn (array $a, array $b): int => [$b['tier'], $b['per_day']] <=> [$a['tier'], $a['per_day']]);
        arsort($systems);
        $systemNames = DB::table('solar_systems')->whereIn('system_id', array_keys($systems))->pluck('name', 'system_id');

        return [
            'pilots' => count(array_filter($people, fn (array $p): bool => $p['colonies'] > 0)),
            'people' => $people,
            'characters' => count($planets),
            'members' => count($ids),
            'colonies' => $colonies,
            'stopped' => $stopped,
            'soon' => $soon,
            'unused' => $unused,
            'attention' => array_slice($attention, 0, 60),
            'products' => $products,
            'value' => $value,
            'per_colony' => $perColony,
            'systems' => array_map(
                fn ($systemId, int $count): array => ['name' => (string) ($systemNames[$systemId] ?? $systemId), 'count' => $count],
                array_keys(array_slice($systems, 0, 10, true)),
                array_slice($systems, 0, 10, true),
            ),
        ];
    }
}
