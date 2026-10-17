<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\SkillPlan;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\ExtractionValue;
use DevilsRage\Seat\Services\GroupFilter;
use DevilsRage\Seat\Services\JitaPrices;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\ViewMode;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillMath;
use DevilsRage\Seat\Skills\SkillPlanParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterSkill;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Web\Http\Controllers\Controller;

/**
 * Corp skill plans, pasted from EVE's skill planner, with how far each character is.
 * Everyone sees their own characters; leaders see the whole corp.
 */
class SkillPlansController extends Controller
{
    /** EVE category of skills. */
    private const CATEGORY_SKILL = 16;

    public function index(Request $request, Leadership $leadership, SkillCheck $skills, GroupFilter $groupFilter): View
    {
        $user = auth()->user();
        $isLeader = $leadership->isLeader($user);
        $plans = SkillPlan::query()->with('skills')->orderBy('name')->get();
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        // Patch 39: the launch group filter (your own characters in that group).
        $group = $groupFilter->resolve((string) $request->query('group'), $user, $isLeader);
        if ($group !== null) {
            $characterIds = array_values(array_intersect($characterIds, $groupFilter->characterIds($group)));
        }
        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->pluck('name', 'character_id');

        $progress = [];
        foreach ($plans as $plan) {
            $progress[$plan->id] = $skills->compare(array_map('intval', $names->keys()->all()), $plan->requirements());
        }

        return view('devilsrage::skillplans.index', [
            'plans' => $plans,
            'names' => $names,
            'progress' => $progress,
            'canManage' => $leadership->canManagePlans($user),
            'isLeader' => $isLeader,
            'groupValue' => $group === null ? '' : 'g:'.$group->id,
            'groupOptions' => $groupFilter->options($user, false),
        ]);
    }

    /**
     * Patch 39: the plan's SP, each character's Done %, time left, SP missing and the
     * Large Skill Injectors that would cover it (with the Jita price); ⧉ Copy plan for EVE,
     * and a character's focus panel with ⧉ Copy missing (one skill level per line).
     */
    public function show(Request $request, SkillPlan $plan, Leadership $leadership, Corporation $corporation, SkillCheck $skills, GroupFilter $groupFilter, JitaPrices $prices): View
    {
        $user = auth()->user();
        $isLeader = $leadership->isLeader($user);
        $characterIds = array_map('intval', $isLeader ? $corporation->memberCharacterIds() : $user->associatedCharacterIds());
        $group = $groupFilter->resolve((string) $request->query('group'), $user, $isLeader);
        if ($group !== null) {
            $characterIds = array_values(array_intersect($characterIds, $groupFilter->characterIds($group)));
        }
        $characterIds = ViewMode::narrow($characterIds, $user);
        $plan->load('skills');
        $requirements = $plan->requirements();
        $skillNames = $skills->names(array_keys($requirements));

        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $status = $skills->compare($characterIds, $requirements);
        uasort($status, fn (array $a, array $b): int => $a['missing_points'] <=> $b['missing_points']);

        $totalSp = CharacterSkill::query()
            ->whereIn('character_id', $characterIds)
            ->groupBy('character_id')
            ->selectRaw('character_id, SUM(skillpoints_in_skill) as total')
            ->pluck('total', 'character_id');
        $injectors = [];
        foreach ($status as $characterId => $row) {
            $injectors[$characterId] = SkillMath::injectorsFor((int) ($totalSp[$characterId] ?? 0), $row['missing_points']);
        }
        $injectorPrice = (float) ($prices->get([ExtractionValue::TYPE_INJECTOR])[ExtractionValue::TYPE_INJECTOR]['sell'] ?? 0);

        $planSp = 0;
        foreach ($skills->skillInfo(array_keys($requirements)) as $skillId => $info) {
            $planSp += SkillMath::skillPointsAt($requirements[$skillId], $info['rank']);
        }

        $focusId = (int) $request->query('character');
        $focus = null;
        if (isset($status[$focusId])) {
            $trained = CharacterSkill::query()
                ->where('character_id', $focusId)
                ->whereIn('skill_id', array_keys($status[$focusId]['missing']))
                ->pluck('trained_skill_level', 'skill_id')
                ->map(fn ($level): int => (int) $level)
                ->all();
            $focus = [
                'id' => $focusId,
                'name' => (string) ($names[$focusId] ?? $focusId),
                'row' => $status[$focusId],
                'trained' => $trained,
                'injectors' => $injectors[$focusId] ?? 0,
                'total_sp' => (int) ($totalSp[$focusId] ?? 0),
                'text' => SkillMath::planText($status[$focusId]['missing'], $skillNames, $trained),
            ];
        }

        return view('devilsrage::skillplans.show', [
            'plan' => $plan,
            'requirements' => $requirements,
            'skillNames' => $skillNames,
            'names' => $names,
            'status' => $status,
            'injectors' => $injectors,
            'injectorPrice' => $injectorPrice,
            'planSp' => $planSp,
            'planText' => SkillMath::planText($requirements, $skillNames),
            'focus' => $focus,
            'isLeader' => $isLeader,
            'canManage' => $leadership->canManagePlans($user),
            'groupValue' => $group === null ? '' : 'g:'.$group->id,
            'groupOptions' => $groupFilter->options($user, $isLeader),
        ]);
    }

    public function store(Request $request, Leadership $leadership, SkillPlanParser $parser): RedirectResponse
    {
        abort_unless($leadership->canManagePlans(auth()->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'skills' => ['required', 'string', 'max:20000'],
        ]);

        $parsed = $parser->parse($data['skills']);
        $known = InvType::query()
            ->join('invGroups', 'invGroups.groupID', '=', 'invTypes.groupID')
            ->where('invGroups.categoryID', self::CATEGORY_SKILL)
            ->whereIn('invTypes.typeName', array_keys($parsed['skills']))
            ->pluck('invTypes.typeID', 'invTypes.typeName')
            ->mapWithKeys(fn ($id, $name): array => [mb_strtolower($name) => (int) $id]);

        $rows = [];
        $unknown = $parsed['ignored'];
        foreach ($parsed['skills'] as $name => $level) {
            $skillId = $known[mb_strtolower($name)] ?? null;
            if ($skillId === null) {
                $unknown[] = $name;

                continue;
            }
            $rows[$skillId] = max($rows[$skillId] ?? 0, $level);
        }

        if ($rows === []) {
            return back()->withErrors(['skills' => 'No skills recognised. Paste the plan from EVE (one "Skill Name 4" per line).'])->withInput();
        }

        DB::transaction(function () use ($data, $rows): void {
            $plan = SkillPlan::query()->updateOrCreate(
                ['name' => trim($data['name'])],
                ['description' => $data['description'] ?? null, 'created_by' => auth()->id()],
            );
            $plan->skills()->delete();
            $position = 0;
            foreach ($rows as $skillId => $level) {
                $plan->skills()->create(['skill_id' => $skillId, 'level' => $level, 'position' => $position++]);
            }
        });

        $message = sprintf('Skill plan "%s" saved with %d skills.', trim($data['name']), count($rows));
        if ($unknown !== []) {
            $message .= ' Not recognised: '.implode(', ', array_slice($unknown, 0, 10)).(count($unknown) > 10 ? ' ...' : '');
        }

        return redirect()->route('devilsrage.skillplans.index')->with('success', $message);
    }

    public function destroy(SkillPlan $plan, Leadership $leadership): RedirectResponse
    {
        abort_unless($leadership->canManagePlans(auth()->user()), 403);
        $plan->delete();

        return redirect()->route('devilsrage.skillplans.index')->with('success', 'Skill plan removed.');
    }
}
