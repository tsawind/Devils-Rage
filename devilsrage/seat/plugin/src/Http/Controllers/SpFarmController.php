<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\FarmCharacter;
use DevilsRage\Seat\Models\Setting;
use DevilsRage\Seat\Services\FarmOverview;
use DevilsRage\Seat\Services\FarmSkills;
use DevilsRage\Seat\Services\Holdings;
use DevilsRage\Seat\Services\JitaPrices;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Skills\FarmMath;
use DevilsRage\Seat\Skills\TradeMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Seat\Web\Http\Controllers\Controller;

/**
 * SP farming: accounts at a glance, farm characters, what an extraction earns in
 * Jita or a citadel (instant or with orders), a calculator for Omega goals, and
 * skill points that could be extracted for quick ISK.
 */
class SpFarmController extends Controller
{
    public const TYPE_PLEX = 44992;

    public const TYPE_INJECTOR = 40520;

    public const TYPE_EXTRACTOR = 40519;

    private const SKILL_ACCOUNTING = 'Accounting';

    private const SKILL_BROKER = 'Broker Relations';

    /** Omega lengths in months the calculator offers. */
    public const OMEGA_MONTHS = [1, 3, 6, 12, 24];

    /**
     * Setting key => [label, default]. PLEX costs aren't published by CCP in one place:
     * leaders check the New Eden Store and correct them. USD = the EVE Store's
     * real-money prices (July 2025).
     */
    public const SETTINGS = [
        'farm_omega_plex_1' => ['Omega 1 month (PLEX)', '500'],
        'farm_omega_plex_3' => ['Omega 3 months (PLEX)', '1200'],
        'farm_omega_plex_6' => ['Omega 6 months (PLEX)', '2100'],
        'farm_omega_plex_12' => ['Omega 12 months (PLEX)', '3600'],
        'farm_omega_plex_24' => ['Omega 24 months (PLEX)', '6600'],
        'farm_mct_plex' => ['MCT 1 month, per slot (PLEX)', '485'],
        'farm_usd_omega_1' => ['Omega 1 month ($)', '19.99'],
        'farm_usd_omega_3' => ['Omega 3 months ($)', '47.98'],
        'farm_usd_omega_6' => ['Omega 6 months ($)', '86.95'],
        'farm_usd_omega_12' => ['Omega 12 months ($)', '149.90'],
        'farm_usd_omega_24' => ['Omega 24 months ($)', '270.99'],
        'farm_usd_mct' => ['MCT 1 month, per slot ($)', '10'],
        'farm_usd_per_500_plex' => ['500 PLEX in the EVE Store ($)', '24.99'],
        'farm_price_plex' => ['PLEX price in ISK (empty = market average)', ''],
        'farm_citadel_broker' => ['Citadel broker fee % (owner fee + surcharge)', '1.0'],
    ];

    public function index(Request $request, Leadership $leadership, OmegaStatus $omega, JitaPrices $jita, FarmSkills $farmSkills, FarmOverview $overview, Holdings $holdings): View
    {
        $user = auth()->user();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->pluck('name', 'character_id');
        $settings = $this->farmSettings();

        // Taxes from the pilot's trading character.
        $tradeLevels = $this->tradeLevels($characterIds);
        $trader = (int) (Setting::read('farm_trader_'.$user->id) ?? 0);
        if (! isset($tradeLevels[$trader])) {
            $trader = (int) (collect($tradeLevels)->sortByDesc(fn (array $levels): int => $levels['accounting'] * 10 + $levels['broker'])->keys()->first() ?? 0);
        }
        $salesTax = TradeMath::salesTax($tradeLevels[$trader]['accounting'] ?? 0);
        $fees = [
            'jita' => TradeMath::npcBrokerFee($tradeLevels[$trader]['broker'] ?? 0),
            'citadel' => (float) $settings['farm_citadel_broker'] / 100,
        ];

        // What one extraction earns, four ways.
        $orders = $jita->get([self::TYPE_INJECTOR, self::TYPE_EXTRACTOR]);
        $injector = $orders[self::TYPE_INJECTOR] ?? ['buy' => 0.0, 'sell' => 0.0, 'source' => '–'];
        $extractor = $orders[self::TYPE_EXTRACTOR] ?? ['buy' => 0.0, 'sell' => 0.0, 'source' => '–'];
        $matrix = [];
        foreach (['jita', 'citadel'] as $market) {
            foreach (['instant', 'patient'] as $mode) {
                $matrix[$market][$mode] = TradeMath::perInjector($mode, $injector, $extractor, $salesTax, $fees[$market]);
            }
        }
        $mode = in_array($request->query('mode'), ['instant', 'patient'], true) ? (string) $request->query('mode') : 'instant';
        $market = in_array($request->query('market'), ['jita', 'citadel'], true) ? (string) $request->query('market') : 'jita';
        $perExtraction = $matrix[$market][$mode]['profit'];

        $plex = $this->plexPrice($settings);
        $omegaMonth = (float) $settings['farm_omega_plex_12'] / 12 * $plex;
        $mctMonth = (float) $settings['farm_mct_plex'] * $plex;

        // Accounts at a glance (and characters that aren't in an account yet).
        $accounts = Account::query()->where('user_id', $user->id)->with('characters')->orderBy('nickname')->get();
        $omegaStatus = $omega->forAccounts($accounts);
        $details = $overview->characters($characterIds, $matrix['jita']['instant']['profit']);
        $held = $holdings->characters($characterIds);
        $inAccounts = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->map(fn ($id): int => (int) $id)->all();

        // Farm characters.
        $farms = FarmCharacter::query()->where('user_id', $user->id)->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $rows = [];
        $totals = ['injectors' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
        foreach ($farms as $characterId => $farm) {
            $detail = $details[$characterId] ?? null;
            $rate = $detail['sp_per_hour'] ?? null;
            $slotCost = match ($farm->slot) {
                'omega' => $omegaMonth,
                'mct' => $mctMonth,
                default => 0.0,
            };
            $month = $rate === null ? null : FarmMath::monthly($rate / 60, $perExtraction + $extractor['sell'], $extractor['sell'], $slotCost);
            if ($month !== null) {
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $month[$key];
                }
            }

            $rows[] = [
                'character_id' => (int) $characterId,
                'name' => (string) ($names[$characterId] ?? $characterId),
                'slot' => $farm->slot,
                'detail' => $detail,
                'month' => $month,
            ];
        }

        $extractTotal = collect($details)->sum(fn (array $detail): int => $detail['extract']['count']);

        return view('devilsrage::spfarm.index', [
            'names' => $names,
            'accounts' => $accounts,
            'omega' => $omegaStatus,
            'holdings' => $holdings->accounts($accounts, $held),
            'details' => $details,
            'loose' => $names->except($inAccounts),
            'rows' => $rows,
            'totals' => $totals,
            'farmIds' => $farms->keys()->map(fn ($id): int => (int) $id)->all(),
            'others' => $names->except($farms->keys()->all()),
            'slots' => FarmCharacter::SLOTS,
            'catalogue' => $farmSkills->catalogue(),
            'injector' => $injector,
            'extractor' => $extractor,
            'matrix' => $matrix,
            'mode' => $mode,
            'market' => $market,
            'salesTax' => $salesTax,
            'fees' => $fees,
            'trader' => $trader,
            'tradeLevels' => $tradeLevels,
            'plex' => $plex,
            'plexSource' => Setting::read('farm_price_plex') !== null ? 'set by leaders' : 'market average',
            'omegaMonth' => $omegaMonth,
            'mctMonth' => $mctMonth,
            'extractTotal' => $extractTotal,
            'extractIsk' => $extractTotal * $matrix['jita']['instant']['profit'],
            'calculator' => [
                'plex' => $plex,
                'usdPerPlex' => (float) $settings['farm_usd_per_500_plex'] / 500,
                'omegaPlex' => collect(self::OMEGA_MONTHS)->mapWithKeys(fn (int $months): array => [$months => (float) $settings['farm_omega_plex_'.$months]])->all(),
                'omegaUsd' => collect(self::OMEGA_MONTHS)->mapWithKeys(fn (int $months): array => [$months => (float) $settings['farm_usd_omega_'.$months]])->all(),
                'mctPlex' => (float) $settings['farm_mct_plex'],
                'mctUsd' => (float) $settings['farm_usd_mct'],
                'perExtraction' => $matrix,
                'accounts' => max(1, $accounts->count()),
                'have' => [
                    'plex' => array_sum(array_column($held, 'plex')) + (int) $accounts->sum('plex_vault'),
                    'isk' => array_sum(array_column($held, 'isk')),
                ],
            ],
            'settings' => collect(self::SETTINGS)->map(fn (array $setting, string $key): array => [
                'label' => $setting[0],
                'value' => $settings[$key],
            ])->all(),
            'canEditSettings' => $leadership->isLeader($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'character_id' => ['required', 'integer', Rule::in($user->associatedCharacterIds())],
            'slot' => ['required', Rule::in(array_keys(FarmCharacter::SLOTS))],
        ], [
            'character_id.in' => 'That character is not one of yours.',
        ]);

        FarmCharacter::query()->updateOrCreate(
            ['character_id' => (int) $data['character_id']],
            ['user_id' => $user->id, 'slot' => $data['slot']],
        );

        return back()->with('success', 'Farm character saved.');
    }

    public function destroy(int $characterId): RedirectResponse
    {
        FarmCharacter::query()->where('user_id', auth()->id())->whereKey($characterId)->delete();

        return back()->with('success', 'Taken off the farm list.');
    }

    /**
     * The character whose Accounting / Broker Relations set the taxes.
     */
    public function trader(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'character_id' => ['required', 'integer', Rule::in($user->associatedCharacterIds())],
        ]);
        Setting::write('farm_trader_'.$user->id, (string) $data['character_id']);

        return back()->with('success', 'Trading character saved.');
    }

    public function settings(Request $request, Leadership $leadership): RedirectResponse
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $rules = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $rules[$key] = ['nullable', 'numeric', 'min:0', 'max:1000000000000'];
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($data): void {
            foreach (array_keys(self::SETTINGS) as $key) {
                Setting::write($key, isset($data[$key]) ? (string) $data[$key] : null);
            }
        });

        return back()->with('success', 'Farm numbers saved for everyone.');
    }

    /**
     * @return array<string, string>
     */
    private function farmSettings(): array
    {
        return collect(self::SETTINGS)->map(fn (array $setting, string $key): string => (string) Setting::read($key, $setting[1]))->all();
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function plexPrice(array $settings): float
    {
        if ($settings['farm_price_plex'] !== '') {
            return (float) $settings['farm_price_plex'];
        }

        // PLEX trades on its own global market, not in Jita: use SeAT's market average.
        return (float) (DB::table('market_prices')->where('type_id', self::TYPE_PLEX)->get(['average', 'average_price'])
            ->map(fn ($row): float => (float) ($row->average > 0 ? $row->average : $row->average_price))
            ->first() ?? 0);
    }

    /**
     * @param  list<int>  $characterIds
     * @return array<int, array{accounting: int, broker: int}>
     */
    private function tradeLevels(array $characterIds): array
    {
        $ids = DB::table('invTypes')
            ->whereIn('typeName', [self::SKILL_ACCOUNTING, self::SKILL_BROKER])
            ->pluck('typeID', 'typeName');
        $rows = CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->whereIn('skill_id', $ids->values()->all())
            ->get(['character_id', 'skill_id', 'trained_skill_level']);

        $levels = array_fill_keys($characterIds, ['accounting' => 0, 'broker' => 0]);
        foreach ($rows as $row) {
            $key = (int) $row->skill_id === (int) ($ids[self::SKILL_ACCOUNTING] ?? 0) ? 'accounting' : 'broker';
            $levels[(int) $row->character_id][$key] = (int) $row->trained_skill_level;
        }

        return $levels;
    }
}
