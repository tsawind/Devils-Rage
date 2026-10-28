<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\PiTemplate as StoredTemplate;
use DevilsRage\Seat\Pi\PiData;
use DevilsRage\Seat\Pi\PiDigest;
use DevilsRage\Seat\Pi\PiPlanner;
use DevilsRage\Seat\Pi\PiTemplate;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\DiscordDm;
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
    public function screen(JitaPrices $jita): View
    {
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
        $prices = $jita->get($priceIds);
        $totals = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0, 'pad' => 0.0, 'customs' => 0.0, 'customs_capacity' => 0];
        foreach ($colonies as &$colony) {
            $sum = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0, 'pad' => 0.0, 'customs' => 0.0, 'customs_capacity' => count($colony['planets']) * PiData::CUSTOMS_CAPACITY];
            foreach ($colony['planets'] as &$info) {
                $info['value'] = $planner->value($info, $prices);
                foreach (['buy', 'split', 'sell'] as $side) {
                    $sum[$side] += $info['value'][$side];
                }
                $sum['pad'] += $info['launchpad']['used'] ?? 0.0;
                $sum['customs'] += $info['customs']['used'] ?? 0.0;
            }
            unset($info);
            foreach ($colony['customs_unplaced'] as $office) {
                $sum['customs'] += $office['used'];
            }
            $colony['totals'] = $sum;
            foreach ($sum as $key => $value) {
                $totals[$key] += $value;
            }
        }
        unset($colony);

        $setting = AccountSetting::query()->find($user->id);
        $leader = Gate::allows('devilsrage-leader');
        $checked = DB::table('character_planets')->whereIn('character_id', $ids)->max('updated_at');

        return view('devilsrage::pi.screen', [
            'run' => $planner->run($characters),
            'colonies' => $colonies,
            'totals' => $totals,
            'priceSource' => collect($prices)->contains(fn (array $p): bool => $p['source'] !== 'Jita 4-4 orders') ? 'Jita 4-4 orders, market average where Jita has none' : 'Jita 4-4 orders',
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
            'corp' => $leader ? $this->corp($now, $jita) : null,
            'templates' => $this->templates((int) $user->id, $leader),
        ]);
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

    /** "Send me a test": today's message right now, whatever it says. */
    public function testAlert(PiDigest $digest, DiscordDm $dm): JsonResponse
    {
        $user = auth()->user();
        $setting = AccountSetting::query()->firstOrNew(['user_id' => $user->id]);
        $message = $digest->message($user, $setting, true) ?? '';

        return response()->json(['sent' => $dm->send((int) $user->id, "(test) ".$message)]);
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
    private function corp(int $now, JitaPrices $jita): array
    {
        $ids = app(Corporation::class)->memberCharacterIds();
        $data = new PiData();
        $planets = $data->planets($ids);
        $planner = new PiPlanner($data->types(), [], $now);
        $slots = $data->slots(array_map('intval', array_keys($planets)));
        $owners = RefreshToken::query()->whereIn('character_id', array_keys($planets))->pluck('user_id', 'character_id');
        $users = User::query()->whereIn('id', $owners->unique()->values())->with('main_character')->get()->keyBy('id');
        $names = DB::table('character_infos')->whereIn('character_id', array_keys($planets))->pluck('name', 'character_id');

        $attention = [];
        $products = [];
        $pilotItems = [];
        $systems = [];
        $pilots = [];
        $colonies = 0;
        $stopped = 0;
        $soon = 0;
        $unused = 0;
        foreach ($planets as $characterId => $list) {
            $userId = $owners[$characterId] ?? null;
            $pilot = $userId !== null && $users->has($userId) ? PersonName::mainName($users[$userId]) : '?';
            $pilots[$pilot] = true;
            $colonies += count($list);
            if (isset($slots[$characterId])) {
                $unused += max(0, $slots[$characterId] - count($list));
            }
            foreach ($list as $planet) {
                $info = $planner->planet($planet);
                $systems[$planet['system_id']] = ($systems[$planet['system_id']] ?? 0) + 1;
                if ($info['status'] === 'stopped') {
                    $stopped++;
                } elseif ($info['status'] === 'soon') {
                    $soon++;
                }
                if (in_array($info['status'], ['stopped', 'soon'], true)) {
                    $attention[] = [
                        'pilot' => $pilot,
                        'character' => (string) ($names[$characterId] ?? $characterId),
                        'planet' => $planet['name'],
                        'status' => $info['status'],
                        'time' => $info['status'] === 'stopped' ? $info['stopped_since'] : $info['next_stop'],
                        'last_update' => $planet['last_update'],
                    ];
                }
                if ($info['making'] !== null || $planet['factories'] === []) {
                    foreach ($planner->products($info) as $item) {
                        $products[$item['name']] = ['type' => $item['type'], 'tier' => $item['tier'], 'per_day' => ($products[$item['name']]['per_day'] ?? 0) + $item['per_day']];
                        $pilotItems[$pilot][$item['type']] = ($pilotItems[$pilot][$item['type']] ?? 0) + $item['per_day'];
                    }
                }
            }
        }
        $prices = $jita->get(array_column($products, 'type'));
        $value = ['buy' => 0.0, 'split' => 0.0, 'sell' => 0.0];
        foreach ($products as $name => $product) {
            $buy = $product['per_day'] * (float) ($prices[$product['type']]['buy'] ?? 0);
            $sell = $product['per_day'] * (float) ($prices[$product['type']]['sell'] ?? 0);
            $products[$name] += ['buy' => $buy, 'split' => ($buy + $sell) / 2, 'sell' => $sell];
            $value['buy'] += $buy;
            $value['sell'] += $sell;
        }
        $value['split'] = ($value['buy'] + $value['sell']) / 2;
        $pilotValues = [];
        foreach ($pilotItems as $pilotName => $items) {
            $split = 0.0;
            foreach ($items as $typeId => $perDay) {
                $split += $perDay * ((float) ($prices[$typeId]['buy'] ?? 0) + (float) ($prices[$typeId]['sell'] ?? 0)) / 2;
            }
            $pilotValues[$pilotName] = $split;
        }
        arsort($pilotValues);
        usort($attention, fn (array $a, array $b): int => [$a['status'] === 'stopped' ? 0 : 1, $a['time'] ?? 0] <=> [$b['status'] === 'stopped' ? 0 : 1, $b['time'] ?? 0]);
        uasort($products, fn (array $a, array $b): int => [$b['tier'], $b['per_day']] <=> [$a['tier'], $a['per_day']]);
        arsort($systems);
        $systemNames = DB::table('solar_systems')->whereIn('system_id', array_keys($systems))->pluck('name', 'system_id');

        return [
            'pilots' => count($pilots),
            'characters' => count($planets),
            'members' => count($ids),
            'colonies' => $colonies,
            'stopped' => $stopped,
            'soon' => $soon,
            'unused' => $unused,
            'attention' => array_slice($attention, 0, 60),
            'products' => $products,
            'value' => $value,
            'pilot_values' => array_slice($pilotValues, 0, 15, true),
            'systems' => array_map(
                fn ($systemId, int $count): array => ['name' => (string) ($systemNames[$systemId] ?? $systemId), 'count' => $count],
                array_keys(array_slice($systems, 0, 10, true)),
                array_slice($systems, 0, 10, true),
            ),
        ];
    }
}
