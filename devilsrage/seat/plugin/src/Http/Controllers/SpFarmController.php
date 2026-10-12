<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\FarmCharacter;
use DevilsRage\Seat\Models\Setting;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Skills\FarmMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterInfoSkill;
use Seat\Web\Http\Controllers\Controller;

/**
 * SP farming: what each farm character earns per month in Large Skill Injectors,
 * after Skill Extractors and the Omega / MCT it costs to train.
 */
class SpFarmController extends Controller
{
    public const TYPE_PLEX = 44992;

    public const TYPE_INJECTOR = 40520;

    public const TYPE_EXTRACTOR = 40519;

    /** Setting key => [label, default]. PLEX counts are per 30 days. */
    public const SETTINGS = [
        'farm_omega_plex' => ['PLEX for 1 month Omega', '500'],
        'farm_mct_plex' => ['PLEX for 1 month MCT', '485'],
        'farm_price_plex' => ['PLEX price (ISK, empty = market)', ''],
        'farm_price_injector' => ['Large Skill Injector price (ISK, empty = market)', ''],
        'farm_price_extractor' => ['Skill Extractor price (ISK, empty = market)', ''],
    ];

    public function index(Leadership $leadership, OmegaStatus $omega): View
    {
        $user = auth()->user();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->pluck('name', 'character_id');
        $totalSp = CharacterInfoSkill::query()->whereIn('character_id', $characterIds)->get(['character_id', 'total_sp', 'unallocated_sp'])->keyBy('character_id');
        $farms = FarmCharacter::query()->where('user_id', $user->id)->whereIn('character_id', $characterIds)->get()->keyBy('character_id');
        $rates = $omega->trainingRates($farms->keys()->map(fn ($id): int => (int) $id)->all());
        $prices = $this->prices();

        $rows = [];
        $totals = ['injectors' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'profit' => 0.0];
        foreach ($farms as $characterId => $farm) {
            $sp = (int) ($totalSp[$characterId]->total_sp ?? 0) + (int) ($totalSp[$characterId]->unallocated_sp ?? 0);
            $rate = $rates[$characterId]['actual'] ?? null;
            $slotCost = match ($farm->slot) {
                'omega' => $prices['omega_month'],
                'mct' => $prices['mct_month'],
                default => 0.0,
            };
            $month = $rate === null ? null : FarmMath::monthly($rate, $prices['injector'], $prices['extractor'], $slotCost);

            if ($month !== null) {
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $month[$key];
                }
            }

            $rows[] = [
                'character_id' => (int) $characterId,
                'name' => (string) ($names[$characterId] ?? $characterId),
                'slot' => $farm->slot,
                'sp' => $sp,
                'ready' => FarmMath::extractorsReady($sp),
                'sp_per_day' => $rate === null ? null : $rate * 60 * 24,
                'month' => $month,
                'alpha' => $rate !== null && $rate < ($rates[$characterId]['omega'] ?? 0) * 0.75,
            ];
        }

        return view('devilsrage::spfarm.index', [
            'rows' => $rows,
            'totals' => $totals,
            'prices' => $prices,
            'others' => $names->except($farms->keys()->all()),
            'slots' => FarmCharacter::SLOTS,
            'settings' => collect(self::SETTINGS)->map(fn (array $setting, string $key): array => [
                'label' => $setting[0],
                'value' => Setting::read($key, $setting[1]),
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
     * ISK prices (leader overrides first, else SeAT's market data) and monthly slot costs.
     *
     * @return array{plex: float, injector: float, extractor: float, omega_month: float, mct_month: float, source: array<string, string>}
     */
    private function prices(): array
    {
        $market = DB::table('market_prices')
            ->whereIn('type_id', [self::TYPE_PLEX, self::TYPE_INJECTOR, self::TYPE_EXTRACTOR])
            ->get(['type_id', 'average', 'average_price'])
            ->mapWithKeys(fn ($row): array => [(int) $row->type_id => (float) ($row->average > 0 ? $row->average : $row->average_price)]);

        $source = [];
        $price = function (string $key, int $typeId) use ($market, &$source): float {
            $override = Setting::read('farm_price_'.$key);
            $source[$key] = $override !== null ? 'set by leaders' : 'market average';

            return $override !== null ? (float) $override : (float) ($market[$typeId] ?? 0);
        };

        $plex = $price('plex', self::TYPE_PLEX);

        return [
            'plex' => $plex,
            'injector' => $price('injector', self::TYPE_INJECTOR),
            'extractor' => $price('extractor', self::TYPE_EXTRACTOR),
            'omega_month' => (float) Setting::read('farm_omega_plex', self::SETTINGS['farm_omega_plex'][1]) * $plex,
            'mct_month' => (float) Setting::read('farm_mct_plex', self::SETTINGS['farm_mct_plex'][1]) * $plex,
            'source' => $source,
        ];
    }
}
