<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use CryptaTech\Seat\Fitting\Models\Doctrine;
use DevilsRage\Seat\Fits\EsiFitConverter;
use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\LaunchGroup;
use DevilsRage\Seat\Models\SkillPlan;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Skills\SkillCheck;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Seat\Eveapi\Models\Assets\CharacterAsset;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * FC planner: who can fly which doctrine fit, by account and launch group.
 */
class PlannerController extends Controller
{
    public function index(Request $request, Leadership $leadership, Corporation $corporation, SkillCheck $skills): View
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $doctrines = Doctrine::query()->orderBy('name')->get(['id', 'name']);
        $plans = SkillPlan::query()->orderBy('name')->get(['id', 'name']);
        $doctrine = $doctrines->firstWhere('id', (int) $request->query('doctrine')) ?? $doctrines->first();
        $plan = $plans->firstWhere('id', (int) $request->query('plan'));
        $groupNames = $this->groupNames();
        $group = in_array((string) $request->query('group'), $groupNames, true) ? (string) $request->query('group') : null;

        $fits = $doctrine === null
            ? collect()
            : Doctrine::query()->with(['fittings.items', 'fittings.ship'])->find($doctrine->id)->fittings->sortBy('name')->values();

        $rows = $this->rows($corporation->memberCharacterIds(), $group);
        $characterIds = array_keys($rows);

        $status = [];
        $requiredIds = [];
        foreach ($fits as $fit) {
            $typeIds = $fit->items
                ->where('flag', '!=', EsiFitConverter::FLAG_CARGO)
                ->pluck('type_id')
                ->push($fit->ship_type_id)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $required = $skills->requiredFor($typeIds);
            $requiredIds += array_fill_keys(array_keys($required), true);
            $status[$fit->fitting_id] = $skills->compare($characterIds, $required);
        }

        $planStatus = $plan === null ? [] : $skills->compare($characterIds, SkillPlan::query()->with('skills')->find($plan->id)->requirements());

        $hulls = CharacterAsset::query()
            ->whereIn('character_id', $characterIds)
            ->whereIn('type_id', $fits->pluck('ship_type_id')->unique()->all())
            ->get(['character_id', 'type_id'])
            ->groupBy('character_id')
            ->map(fn ($assets) => $assets->pluck('type_id')->map(fn ($id): int => (int) $id)->unique()->all());

        return view('devilsrage::planner.index', [
            'doctrines' => $doctrines,
            'doctrine' => $doctrine,
            'plans' => $plans,
            'plan' => $plan,
            'group' => $group,
            'groupNames' => $groupNames,
            'fits' => $fits,
            'rows' => $rows,
            'status' => $status,
            'planStatus' => $planStatus,
            'hulls' => $hulls,
            'closeSeconds' => SkillCheck::CLOSE_SECONDS,
            'skillNames' => $skills->names(array_keys($requiredIds)),
            'alpha' => array_flip(app(OmegaStatus::class)->alphaCharacters(array_map('intval', $characterIds))),
        ]);
    }

    /**
     * Filter choices: Main / Second / Third. Pilots' own launch groups are private.
     *
     * @return list<string>
     */
    private function groupNames(): array
    {
        return LaunchGroup::query()->whereNull('user_id')->orderBy('position')->pluck('name')->all();
    }

    /**
     * Corp characters with their pilot, account (when shared) and launch group.
     *
     * @param  list<int>  $characterIds
     * @return array<int, array{name: string, pilot: string, user_id: int, account: ?string, omega_ended: bool, account_key: string, groups: list<array{name: string, class: string}>}>
     */
    private function rows(array $characterIds, ?string $group): array
    {
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $owners = RefreshToken::query()->whereIn('character_id', $characterIds)->pluck('user_id', 'character_id');
        $users = User::query()->whereIn('id', $owners->unique()->values())->with('main_character')->get()->keyBy('id');
        $private = AccountSetting::query()->where('share', false)->pluck('user_id')->flip();

        $links = AccountCharacter::query()
            ->whereIn('character_id', $characterIds)
            ->with(['account', 'launchGroups' => fn ($query) => $query->whereNull('devilsrage_launch_groups.user_id')])
            ->get()
            ->keyBy('character_id');

        $rows = [];
        foreach ($characterIds as $characterId) {
            $userId = (int) ($owners[$characterId] ?? 0);
            $link = $private->has($userId) ? null : $links->get($characterId);

            $groups = $link?->launchGroups ?? collect();
            if ($group !== null && ! $groups->contains(fn (LaunchGroup $item): bool => strcasecmp($item->name, $group) === 0)) {
                continue;
            }

            $rows[$characterId] = [
                'name' => (string) ($names[$characterId] ?? $characterId),
                'pilot' => (string) ($users->get($userId)?->main_character?->name ?? $users->get($userId)?->name ?? 'Unknown'),
                'user_id' => $userId,
                'account' => $link?->account?->nickname,
                'omega_ended' => $link?->account?->omega_until !== null && $link->account->omega_until->isPast() && ! $link->account->omega_until->isToday(),
                // Characters on one account can't fly at the same time; without a shared
                // account each character stands alone.
                'account_key' => $link instanceof AccountCharacter ? 'a'.$link->account_id : 'c'.$characterId,
                'groups' => $groups->map(fn (LaunchGroup $item): array => ['name' => $item->name, 'class' => $item->pillClass()])->all(),
            ];
        }

        uasort($rows, fn (array $a, array $b): int => [$a['pilot'], $a['account'] ?? '~', $a['name']] <=> [$b['pilot'], $b['account'] ?? '~', $b['name']]);

        return $rows;
    }
}
