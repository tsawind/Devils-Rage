<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\LaunchGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Web\Http\Controllers\Controller;

/**
 * Patch 66: launch groups for the desktop app. Your accounts (SeAT) is where groups are made;
 * the app mirrors them (its pedal keys, on / off switches and previews stay on each PC).
 *
 * - json(): your accounts (number, characters in order) and every launch group you can use,
 *   members in account order then character order. The Your accounts page hands this to the
 *   app when it runs inside it.
 * - import(): groups made on one PC (e.g. imported from EVE-O Preview) become your own groups
 *   here, ticking the characters by name.
 * - Directors add corp launch groups everyone can tick ("Roll fleet", "PI run"), the way
 *   Main / Second / Third already work.
 */
class LaunchGroupsController extends Controller
{
    private const MAX_OWN_GROUPS = 20;

    private const MAX_CORP_GROUPS = 20;

    public function json(): JsonResponse
    {
        $user = auth()->user();
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['characters' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'characters.launchGroups'])
            ->ordered()
            ->get();
        $names = CharacterInfo::query()
            ->whereIn('character_id', $accounts->flatMap(fn (Account $a) => $a->characters->pluck('character_id'))->all())
            ->pluck('name', 'character_id');
        $groups = LaunchGroup::forUser((int) $user->id);

        $outAccounts = [];
        $members = [];
        foreach ($accounts as $index => $account) {
            $chars = [];
            foreach ($account->characters as $link) {
                $name = (string) ($names[$link->character_id] ?? $link->displayName());
                $chars[] = ['id' => (int) $link->character_id, 'name' => $name];
                foreach ($link->launchGroups as $group) {
                    $members[$group->id][] = ['name' => $name, 'account' => $account->nickname, 'character_id' => (int) $link->character_id];
                }
            }
            $outAccounts[] = [
                'id' => (int) $account->id,
                'nickname' => (string) $account->nickname,
                // "29" → client profile settings_29 (how Tsa numbers accounts and profiles).
                'profile' => self::profileFor((string) $account->nickname),
                'order' => $index + 1,
                'characters' => $chars,
            ];
        }

        return response()->json([
            'synced_at' => now()->getTimestamp(),
            'accounts' => $outAccounts,
            'groups' => $groups->map(fn (LaunchGroup $group): array => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'kind' => $group->user_id !== null ? 'own' : ($group->locked ? 'corp-slot' : 'corp'),
                'members' => $members[$group->id] ?? [],
            ])->values()->all(),
        ]);
    }

    /** The client profile number an account nickname stands for ("29", "Acct 07" → "29", "7"). */
    public static function profileFor(string $nickname): ?string
    {
        return preg_match('/(\d{1,3})/', $nickname, $m) === 1 ? (string) (int) $m[1] : null;
    }

    public function import(Request $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'groups' => ['required', 'array', 'max:20'],
            'groups.*.name' => ['required', 'string', 'max:40'],
            'groups.*.members' => ['array', 'max:150'],
            'groups.*.members.*' => ['string', 'max:64'],
        ]);
        $links = AccountCharacter::query()
            ->whereIn('account_id', Account::query()->where('user_id', $user->id)->pluck('id'))
            ->get();
        $byName = [];
        $names = CharacterInfo::query()->whereIn('character_id', $links->pluck('character_id')->all())->pluck('name', 'character_id');
        foreach ($links as $link) {
            $byName[mb_strtolower((string) ($names[$link->character_id] ?? $link->displayName()))] = $link;
        }

        $made = 0;
        $ticked = 0;
        $missing = [];
        DB::transaction(function () use ($data, $user, $byName, &$made, &$ticked, &$missing): void {
            foreach ($data['groups'] as $incoming) {
                $name = trim($incoming['name']);
                $group = LaunchGroup::query()->where('user_id', $user->id)->where('name', $name)->first();
                if ($group === null) {
                    if (LaunchGroup::query()->where('user_id', $user->id)->count() >= self::MAX_OWN_GROUPS) {
                        continue;
                    }
                    if (LaunchGroup::query()->whereNull('user_id')->where('name', $name)->exists()) {
                        $name .= ' (mine)';
                    }
                    $group = LaunchGroup::query()->create(['user_id' => $user->id, 'name' => mb_substr($name, 0, 40), 'locked' => false]);
                    $made++;
                }
                foreach ($incoming['members'] ?? [] as $member) {
                    $link = $byName[mb_strtolower(trim($member))] ?? null;
                    if ($link === null) {
                        $missing[] = $member;

                        continue;
                    }
                    $group->characters()->syncWithoutDetaching([$link->id]);
                    $ticked++;
                }
            }
        });

        return response()->json([
            'made' => $made,
            'ticked' => $ticked,
            'missing' => array_values(array_unique($missing)),
        ]);
    }

    /**
     * Patch 70: the app's "New launch group" page: make (or remake) one of your own groups with
     * exactly these characters, ticked in Your accounts.
     */
    public function appSave(Request $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'character_ids' => ['required', 'array', 'min:1', 'max:150'],
            'character_ids.*' => ['integer'],
        ]);
        $name = trim($data['name']);
        if (LaunchGroup::query()->whereNull('user_id')->where('name', $name)->exists()) {
            return response()->json(['message' => 'That name is a corp group already: pick another.'], 422);
        }
        $links = AccountCharacter::query()
            ->whereIn('account_id', Account::query()->where('user_id', $user->id)->pluck('id'))
            ->whereIn('character_id', array_map('intval', $data['character_ids']))
            ->get();
        if ($links->isEmpty()) {
            return response()->json(['message' => 'None of those characters is in your accounts.'], 422);
        }
        $group = LaunchGroup::query()->where('user_id', $user->id)->where('name', $name)->first();
        if ($group === null) {
            if (LaunchGroup::query()->where('user_id', $user->id)->count() >= self::MAX_OWN_GROUPS) {
                return response()->json(['message' => 'You have '.self::MAX_OWN_GROUPS.' launch groups already: delete one first.'], 422);
            }
            $group = LaunchGroup::query()->create(['user_id' => $user->id, 'name' => $name, 'locked' => false]);
        }
        $group->characters()->sync($links->pluck('id')->all());

        return response()->json(['id' => (int) $group->id, 'ticked' => $links->count()]);
    }

    /** Patch 70: delete one of your own groups from the app. */
    public function appDelete(LaunchGroup $launchGroup): JsonResponse
    {
        abort_unless($launchGroup->user_id !== null && $launchGroup->user_id === (int) auth()->id(), 403);
        $launchGroup->delete();

        return response()->json(['deleted' => true]);
    }

    /** Directors: a corp launch group every pilot can tick on their characters. */
    public function storeCorp(Request $request): RedirectResponse
    {
        abort_unless(Gate::allows('devilsrage-leader'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('devilsrage_launch_groups', 'name')->whereNull('user_id')],
        ], ['name.unique' => 'There is already a corp launch group with that name.']);
        if (LaunchGroup::query()->whereNull('user_id')->where('locked', false)->count() >= self::MAX_CORP_GROUPS) {
            return back()->withErrors(['name' => 'Up to '.self::MAX_CORP_GROUPS.' corp launch groups.']);
        }
        LaunchGroup::query()->create(['user_id' => null, 'name' => trim($data['name']), 'locked' => false, 'position' => 10]);

        return back()->with('success', 'Corp launch group "'.trim($data['name']).'" added: every pilot can tick it on their characters now.');
    }

    public function deleteCorp(LaunchGroup $launchGroup): RedirectResponse
    {
        abort_unless(Gate::allows('devilsrage-leader'), 403);
        abort_unless($launchGroup->user_id === null && ! $launchGroup->locked, 403);
        $launchGroup->delete();

        return back()->with('success', 'Corp launch group "'.$launchGroup->name.'" removed.');
    }
}
