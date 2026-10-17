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
use DevilsRage\Seat\Services\GroupFilter;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Services\ViewMode;
use DevilsRage\Seat\Skills\SkillCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request, Leadership $leadership, Corporation $corporation, SkillCheck $skills, GroupFilter $groupFilter): View
    {
        $user = auth()->user();
        abort_unless($leadership->isLeader($user), 403);

        $doctrines = Doctrine::query()->orderBy('name')->get(['id', 'name']);
        $plans = SkillPlan::query()->orderBy('name')->get(['id', 'name']);
        $doctrine = $doctrines->firstWhere('id', (int) $request->query('doctrine')) ?? $doctrines->first();
        $plan = $plans->firstWhere('id', (int) $request->query('plan'));
        // Patch 39: corp groups, your groups and (leaders) every pilot's groups.
        $group = $groupFilter->resolve((string) $request->query('group'), $user, true);

        $fits = $doctrine === null
            ? collect()
            : Doctrine::query()->with(['fittings.items', 'fittings.ship'])->find($doctrine->id)->fittings->sortBy('name')->values();

        // Corp members, plus every character pilots put in a shared account (alts outside
        // the corp, expired SeAT logins and characters that aren't on SeAT at all).
        $memberIds = $corporation->memberCharacterIds();
        $private = AccountSetting::query()->where('share', false)->pluck('user_id')->all();
        $accountIds = AccountCharacter::query()
            ->whereHas('account', fn ($query) => $query->whereNotIn('user_id', $private))
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $candidates = array_values(array_unique([...$memberIds, ...$accountIds]));
        if ($group !== null) {
            $candidates = array_values(array_intersect($candidates, $groupFilter->characterIds($group)));
        }
        // Patch 39: "My characters only" narrows the planner too.
        $rows = $this->rows(ViewMode::narrow($candidates, $user), $memberIds);
        // Only characters SeAT has skills for are compared.
        $characterIds = array_keys(array_filter($rows, fn (array $row): bool => $row['flag'] === null || $row['flag'] === 'corp'));

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
            'groupValue' => $group === null ? '' : 'g:'.$group->id,
            'groupOptions' => $groupFilter->options($user, true),
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
     * Characters with their pilot, account (when shared), launch group and why SeAT may
     * not know their skills: flag "external" (not on SeAT), "token" (login expired),
     * "corp" (not in the corp right now) or null.
     *
     * @param  list<int>  $characterIds
     * @param  list<int>  $memberIds
     * @return array<int, array{name: string, pilot: string, user_id: int, account: ?string, omega_ended: bool, account_key: string, groups: list<array{name: string, class: string}>, flag: ?string, fits: list<int>}>
     */
    private function rows(array $characterIds, array $memberIds): array
    {
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $owners = RefreshToken::query()->whereIn('character_id', $characterIds)->pluck('user_id', 'character_id');
        $members = array_flip($memberIds);
        $accountOwners = AccountCharacter::query()->whereIn('character_id', $characterIds)->with('account')->get()
            ->mapWithKeys(fn (AccountCharacter $link): array => [(int) $link->character_id => (int) $link->account?->user_id]);
        $users = User::query()->whereIn('id', $owners->merge($accountOwners)->unique()->values())->with('main_character')->get()->keyBy('id');
        $externalFits = DB::table(AccountCharacter::EXTERNAL_FITS)->get()->groupBy('account_character_id')
            ->map(fn ($rows) => $rows->pluck('fitting_id')->map(fn ($id): int => (int) $id)->all());
        $private = AccountSetting::query()->where('share', false)->pluck('user_id')->flip();

        $links = AccountCharacter::query()
            ->whereIn('character_id', $characterIds)
            ->with(['account', 'launchGroups' => fn ($query) => $query->whereNull('devilsrage_launch_groups.user_id')])
            ->get()
            ->keyBy('character_id');

        $rows = [];
        foreach ($characterIds as $characterId) {
            $userId = (int) ($owners[$characterId] ?? $accountOwners[$characterId] ?? 0);
            $link = $private->has($userId) ? null : $links->get($characterId);

            $groups = $link?->launchGroups ?? collect();

            $external = (bool) $links->get($characterId)?->external;
            $rows[$characterId] = [
                'flag' => match (true) {
                    $external => 'external',
                    ! isset($owners[$characterId]) => 'token',
                    ! isset($members[$characterId]) => 'corp',
                    default => null,
                },
                'fits' => $external ? ($externalFits[$links->get($characterId)->id] ?? []) : [],
                'name' => (string) ($names[$characterId] ?? $links->get($characterId)?->displayName() ?? $characterId),
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
