<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Pi\PiData;
use DevilsRage\Seat\Pi\PiDigest;
use DevilsRage\Seat\Pi\PiPlanner;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\DiscordDm;
use DevilsRage\Seat\Services\PersonName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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
    public function screen(): View
    {
        $user = auth()->user();
        $ids = array_map('intval', $user->associatedCharacterIds());
        $data = new PiData();
        $planets = $data->planets($ids);
        $now = now()->getTimestamp();
        $planner = new PiPlanner($data->types(), $data->history($ids), $now);
        $order = $data->loginOrder((int) $user->id, $ids);
        $slots = $data->slots($ids);

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
        }
        unset($colony, $info);

        $setting = AccountSetting::query()->find($user->id);
        $leader = Gate::allows('devilsrage-leader');
        $checked = DB::table('character_planets')->whereIn('character_id', $ids)->max('updated_at');

        return view('devilsrage::pi.screen', [
            'run' => $planner->run($characters),
            'colonies' => $colonies,
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
            'corp' => $leader ? $this->corp($now) : null,
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

    /** "Send me a test": today's message right now, whatever it says. */
    public function testAlert(PiDigest $digest, DiscordDm $dm): JsonResponse
    {
        $user = auth()->user();
        $setting = AccountSetting::query()->firstOrNew(['user_id' => $user->id]);
        $message = $digest->message($user, $setting, true) ?? '';

        return response()->json(['sent' => $dm->send((int) $user->id, "(test) ".$message)]);
    }

    /**
     * Directors: every corp member's colonies, what needs attention, what the corp makes.
     *
     * @return array<string, mixed>
     */
    private function corp(int $now): array
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
                if ($info['making'] !== null) {
                    foreach ($info['making']['per_hour'] as $name => $perHour) {
                        $products[$name] = ['tier' => $info['making']['tier'], 'per_day' => ($products[$name]['per_day'] ?? 0) + $perHour * 24];
                    }
                } elseif ($planet['factories'] === []) {
                    foreach ($planet['extractors'] as $extractor) {
                        if (! empty($extractor['qty']) && ! empty($extractor['cycle_time']) && $extractor['expiry'] !== null && $extractor['expiry'] > $now) {
                            $name = $extractor['product_name'];
                            $products[$name] = ['tier' => 0, 'per_day' => ($products[$name]['per_day'] ?? 0) + (int) round($extractor['qty'] * 86400 / $extractor['cycle_time'])];
                        }
                    }
                }
            }
        }
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
            'systems' => array_map(
                fn ($systemId, int $count): array => ['name' => (string) ($systemNames[$systemId] ?? $systemId), 'count' => $count],
                array_keys(array_slice($systems, 0, 10, true)),
                array_slice($systems, 0, 10, true),
            ),
        ];
    }
}
