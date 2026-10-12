<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use CryptaTech\Seat\Fitting\Models\Doctrine;
use DevilsRage\Seat\Fits\EsiFitConverter;
use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\Setting;
use DevilsRage\Seat\Services\AssetLocator;
use DevilsRage\Seat\Services\AssetSearch;
use DevilsRage\Seat\Services\Clones;
use DevilsRage\Seat\Services\Holdings;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\Locations;
use DevilsRage\Seat\Services\MenuLayout;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Skills\CharacterSkillQueue;
use Seat\Web\Http\Controllers\Controller;

/**
 * The pilot's own pages: a Devil's Rage start page, assets and clones across all
 * characters, and how the sidebar is arranged.
 */
class HomeController extends Controller
{
    public function home(Leadership $leadership, OmegaStatus $omega, Holdings $holdings): View
    {
        $user = auth()->user();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $accounts = Account::query()->where('user_id', $user->id)->with('characters')->orderBy('nickname')->get();
        $status = $omega->forAccounts($accounts);
        $held = $holdings->characters($characterIds);

        // Training: who isn't training, and whose queue runs out within a day.
        $queueEnds = CharacterSkillQueue::query()
            ->whereIn('character_id', $characterIds)
            ->where('finish_date', '>', Carbon::now())
            ->groupBy('character_id')
            ->selectRaw('character_id, MAX(finish_date) as ends')
            ->pluck('ends', 'character_id');
        $idle = $names->except($queueEnds->keys()->all())->sort()->values()->all();
        $endingSoon = $queueEnds->filter(fn ($ends): bool => Carbon::parse($ends)->lt(Carbon::now()->addDay()))
            ->map(fn ($ends, $id): array => ['name' => (string) ($names[$id] ?? $id), 'ends' => Carbon::parse($ends)])
            ->sortBy('ends')->values()->all();

        return view('devilsrage::home.index', [
            'accounts' => $accounts,
            'omega' => $status,
            'states' => collect($status)->countBy('state')->all(),
            'soonest' => collect($status)->filter(fn (array $om): bool => $om['until'] !== null && ! $om['auto'])->sortBy('until')->first(),
            'plex' => array_sum(array_column($held, 'plex')) + (int) $accounts->sum('plex_vault'),
            'isk' => array_sum(array_column($held, 'isk')),
            'characterCount' => count($characterIds),
            'idle' => $idle,
            'endingSoon' => $endingSoon,
            'doctrines' => $this->doctrines($user->id, $characterIds),
            'isLeader' => $leadership->isLeader($user),
        ]);
    }

    public function stuff(Request $request, AssetSearch $search, AssetLocator $locator, Locations $locations, Clones $clones): View
    {
        $user = auth()->user();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->pluck('name', 'character_id');
        $only = (int) $request->query('character');
        $searchIds = isset($names[$only]) ? [$only] : $characterIds;
        $query = trim((string) $request->query('q', ''));
        $shipsOnly = $request->boolean('ships');

        $results = $search->search($searchIds, $query, $shipsOnly);
        $systems = $query === '' && ! $shipsOnly ? array_slice($locator->systems($searchIds), 0, 40) : [];
        $systemNames = $locations->systems(array_column($systems, 'system_id'));

        return view('devilsrage::home.stuff', [
            'names' => $names,
            'only' => isset($names[$only]) ? $only : null,
            'query' => $query,
            'shipsOnly' => $shipsOnly,
            'results' => $results,
            'limit' => AssetSearch::LIMIT,
            'systems' => $systems,
            'systemNames' => $systemNames,
            'clones' => $clones->forCharacters(isset($names[$only]) ? [$only] : $characterIds),
            'tab' => $request->query('tab') === 'clones' ? 'clones' : 'assets',
        ]);
    }

    public function menu(MenuLayout $layout, Leadership $leadership): View
    {
        $user = auth()->user();
        $mine = $layout->forUser((int) $user->id);
        $sidebar = (array) config('package.sidebar', []);

        $rows = [];
        $position = 0;
        foreach ($layout->menus() as $key => $menu) {
            $position++;
            $rows[] = [
                'key' => $key,
                'name' => $menu['name'],
                'icon' => $menu['icon'],
                'folder' => $mine[$key]['folder'] ?? $layout->defaultFolder($key, $sidebar[$key] ?? []),
                'order' => $mine[$key]['order'] ?? 1000 + $position,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$a['folder'] === MenuLayout::TOP ? 0 : 1, $a['folder'], $a['order']] <=> [$b['folder'] === MenuLayout::TOP ? 0 : 1, $b['folder'], $b['order']]);

        return view('devilsrage::home.menu', [
            'rows' => $rows,
            'folders' => array_values(array_unique([...MenuLayout::FOLDERS, ...array_filter(array_column($rows, 'folder'), fn (string $folder): bool => ! in_array($folder, [MenuLayout::TOP, MenuLayout::HIDDEN], true))])),
            'hasOwn' => $layout->hasOwn((int) $user->id),
            'isLeader' => $leadership->isLeader($user),
            'startPage' => self::startsOnHome((int) $user->id),
        ]);
    }

    public function saveMenu(Request $request, MenuLayout $layout, Leadership $leadership): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'action' => ['required', 'in:mine,default,reset'],
            'menus' => ['array'],
            'menus.*.folder' => ['nullable', 'string', 'max:30'],
            'menus.*.order' => ['nullable', 'integer', 'between:0,9999'],
            'start' => ['boolean'],
        ]);

        Setting::write('start_home_'.$user->id, $request->boolean('start') ? '1' : '0');

        if ($data['action'] === 'reset') {
            $layout->save((int) $user->id, null);

            return back()->with('success', 'Your menu follows the corp default again.');
        }

        $known = array_keys($layout->menus());
        $saved = [];
        foreach ($data['menus'] ?? [] as $key => $menu) {
            if (in_array((string) $key, $known, true)) {
                $saved[(string) $key] = ['folder' => trim((string) ($menu['folder'] ?? '')), 'order' => (int) ($menu['order'] ?? 0)];
            }
        }

        if ($data['action'] === 'default') {
            abort_unless($leadership->isLeader($user), 403);
            $layout->save(null, $saved);

            return back()->with('success', 'Saved as the corp default (pilots who arranged their own menu keep theirs).');
        }

        $layout->save((int) $user->id, $saved);

        return back()->with('success', 'Menu saved.');
    }

    /**
     * Whether SeAT's home page sends this pilot to the Devil's Rage home (default yes).
     */
    public static function startsOnHome(int $userId): bool
    {
        return Setting::read('start_home_'.$userId, '1') === '1';
    }

    /**
     * Per doctrine: each fit, how many of the pilot's characters can fly it, and the
     * closest one that can't yet. Cached for 15 minutes.
     *
     * @param  list<int>  $characterIds
     * @return list<array{name: string, fits: list<array{name: string, ship: string, can: int, closest: string|null}>}>
     */
    private function doctrines(int $userId, array $characterIds): array
    {
        return Cache::remember('devilsrage.home.doctrines.'.$userId, 900, function () use ($characterIds): array {
            $skills = app(SkillCheck::class);
            $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');

            return Doctrine::query()->with(['fittings.items', 'fittings.ship'])->orderBy('name')->get()
                ->map(fn (Doctrine $doctrine): array => [
                    'name' => (string) $doctrine->name,
                    'fits' => $doctrine->fittings->sortBy('name')->map(function ($fit) use ($skills, $characterIds, $names): array {
                        $typeIds = $fit->items->where('flag', '!=', EsiFitConverter::FLAG_CARGO)->pluck('type_id')->push($fit->ship_type_id)
                            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
                        $status = $characterIds === [] ? [] : $skills->compare($characterIds, $skills->requiredFor($typeIds));
                        $can = collect($status)->filter(fn (array $row): bool => $row['missing'] === []);
                        $closest = collect($status)->reject(fn (array $row): bool => $row['missing'] === [])->sortBy('seconds')->keys()->first();

                        return [
                            'name' => (string) $fit->name,
                            'ship' => (string) ($fit->ship->typeName ?? '?'),
                            'can' => $can->count(),
                            'closest' => $closest === null ? null : ($names[$closest] ?? $closest).' · '.SkillMath::duration($status[$closest]['seconds']),
                        ];
                    })->values()->all(),
                ])->values()->all();
        });
    }
}
