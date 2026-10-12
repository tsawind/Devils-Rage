<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\SkillPlan;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Skills\SkillCheck;
use DevilsRage\Seat\Skills\SkillPlanParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
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

    public function index(Leadership $leadership, SkillCheck $skills): View
    {
        $user = auth()->user();
        $plans = SkillPlan::query()->with('skills')->orderBy('name')->get();
        $characterIds = $user->associatedCharacterIds();
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
            'isLeader' => $leadership->isLeader($user),
        ]);
    }

    public function show(SkillPlan $plan, Leadership $leadership, Corporation $corporation, SkillCheck $skills): View
    {
        $user = auth()->user();
        $isLeader = $leadership->isLeader($user);
        $characterIds = $isLeader ? $corporation->memberCharacterIds() : $user->associatedCharacterIds();
        $plan->load('skills');
        $requirements = $plan->requirements();

        $names = CharacterInfo::query()->whereIn('character_id', $characterIds)->pluck('name', 'character_id');
        $status = $skills->compare(array_map('intval', $characterIds), $requirements);
        uasort($status, fn (array $a, array $b): int => $a['missing_points'] <=> $b['missing_points']);

        return view('devilsrage::skillplans.show', [
            'plan' => $plan,
            'requirements' => $requirements,
            'skillNames' => $skills->names(array_keys($requirements)),
            'names' => $names,
            'status' => $status,
            'isLeader' => $isLeader,
            'canManage' => $leadership->canManagePlans($user),
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
