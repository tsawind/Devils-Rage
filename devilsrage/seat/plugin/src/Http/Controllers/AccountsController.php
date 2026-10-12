<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\LaunchGroup;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\Holdings;
use DevilsRage\Seat\Services\OmegaStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * "My accounts": characters grouped into EVE accounts (by nickname), in the pilot's
 * order, tagged with launch groups. Main / Second / Third hold one character per
 * account; the pilot's own groups have no such lock (the page warns instead).
 */
class AccountsController extends Controller
{
    /** How many groups of their own a pilot may make. */
    private const MAX_OWN_GROUPS = 20;

    public function index(): View
    {
        $user = auth()->user();
        $characters = CharacterInfo::query()
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->orderBy('name')
            ->get()
            ->keyBy('character_id');

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('characters.launchGroups')
            ->orderBy('nickname')
            ->get();

        $groups = LaunchGroup::forUser($user->id);
        $omega = app(OmegaStatus::class);
        $assigned = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->all();
        $holdings = app(Holdings::class);

        return view('devilsrage::accounts.index', [
            'accounts' => $accounts,
            'characters' => $characters,
            'corpGroups' => $groups->where('locked', true)->values(),
            'ownGroups' => $groups->where('locked', false)->values(),
            'members' => $this->members($accounts, $groups, $characters),
            'omega' => $omega->forAccounts($accounts),
            'holdings' => $holdings->accounts($accounts, $holdings->characters(array_map('intval', $assigned))),
            'unassigned' => $characters->except($assigned)->values(),
            'share' => AccountSetting::sharesFor($user->id),
            'maxCharacters' => Account::MAX_CHARACTERS,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'nickname' => ['required', 'string', 'max:32', Rule::unique('devilsrage_accounts', 'nickname')->where('user_id', $user->id)],
        ], [
            'nickname.unique' => 'You already have an account with that nickname.',
        ]);

        $nickname = trim($data['nickname']);
        if ($this->isCharacterName($user, $nickname)) {
            return back()->withErrors(['nickname' => 'Pick a nickname that is not one of your character names (and not your real login name).'])->withInput();
        }

        Account::query()->create(['user_id' => $user->id, 'nickname' => $nickname]);

        return back()->with('success', "Account \"{$nickname}\" added.");
    }

    public function renameAccount(Request $request, Account $account): RedirectResponse
    {
        $user = auth()->user();
        $this->authorizeOwner($account);
        $data = $request->validate([
            'nickname' => ['required', 'string', 'max:32', Rule::unique('devilsrage_accounts', 'nickname')->where('user_id', $user->id)->ignore($account->id)],
        ]);

        $nickname = trim($data['nickname']);
        if ($this->isCharacterName($user, $nickname)) {
            return back()->withErrors(['nickname' => 'Pick a nickname that is not one of your character names.']);
        }

        $account->update(['nickname' => $nickname]);

        return back()->with('success', 'Account renamed.');
    }

    /**
     * Omega and Multiple Character Training of one account: end dates (empty =
     * unknown / Alpha / none), whether they renew by themselves, and MCT slots.
     */
    public function setOmega(Request $request, Account $account): RedirectResponse
    {
        $this->authorizeOwner($account);
        $data = $request->validate([
            'omega_until' => ['nullable', 'date', 'after:2003-01-01', 'before:+5 years'],
            'omega_auto' => ['boolean'],
            'mct_slots' => ['required', 'integer', 'between:0,2'],
            'mct_until' => ['nullable', 'date', 'after:2003-01-01', 'before:+5 years'],
            'mct_auto' => ['boolean'],
            'plex_vault' => ['nullable', 'integer', 'between:0,100000000'],
        ], [
            'omega_until.before' => 'That date is too far ahead; use the date Omega runs out.',
            'mct_until.before' => 'That date is too far ahead; use the date MCT runs out.',
        ]);

        $omegaUntil = $data['omega_until'] ?? null;
        $vault = isset($data['plex_vault']) ? (int) $data['plex_vault'] : null;
        $mctUntil = (int) $data['mct_slots'] > 0 ? ($data['mct_until'] ?? null) : null;
        $account->update([
            'omega_until' => $omegaUntil,
            'omega_auto' => $request->boolean('omega_auto'),
            'mct_slots' => (int) $data['mct_slots'],
            'mct_until' => $mctUntil,
            'mct_auto' => (int) $data['mct_slots'] > 0 && $request->boolean('mct_auto'),
            'plex_vault' => $vault,
            'plex_vault_at' => $vault === $account->plex_vault ? $account->plex_vault_at : now(),
            // A new date starts the reminders over.
            'omega_reminded' => $account->omega_until?->format('Y-m-d') === ($omegaUntil === null ? null : date('Y-m-d', strtotime($omegaUntil))) ? $account->omega_reminded : null,
            'mct_reminded' => $account->mct_until?->format('Y-m-d') === ($mctUntil === null ? null : date('Y-m-d', strtotime($mctUntil))) ? $account->mct_reminded : null,
        ]);

        return back()->with('success', 'Omega / MCT / PLEX saved for '.$account->nickname.'. Reminders go out by Discord DM 7 days and 1 day before an end date, unless it renews by itself.');
    }

    public function deleteAccount(Account $account): RedirectResponse
    {
        $this->authorizeOwner($account);
        $account->delete();

        return back()->with('success', 'Account removed; its characters are unassigned again.');
    }

    public function addCharacter(Request $request, Account $account): RedirectResponse
    {
        $user = auth()->user();
        $this->authorizeOwner($account);
        $data = $request->validate([
            'character_id' => ['required', 'integer', Rule::in($user->associatedCharacterIds())],
        ], [
            'character_id.in' => 'That character is not one of yours.',
        ]);

        if ($account->characters()->count() >= Account::MAX_CHARACTERS) {
            return back()->withErrors(['character_id' => 'An EVE account holds at most '.Account::MAX_CHARACTERS.' characters.']);
        }

        if (AccountCharacter::query()->where('character_id', $data['character_id'])->exists()) {
            return back()->withErrors(['character_id' => 'That character is already in an account. Remove it there first.']);
        }

        $account->characters()->create([
            'character_id' => (int) $data['character_id'],
            'position' => (int) $account->characters()->max('position') + 1,
        ]);

        return back()->with('success', 'Character added.');
    }

    /**
     * The corp launch group of one character: Main, Second, Third or none. Each
     * account holds one character per corp group, so picking one takes it away from
     * the other characters on that account.
     */
    public function setCorpGroup(Request $request, AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);

        $corpGroups = LaunchGroup::query()->whereNull('user_id')->where('locked', true)->get()->keyBy('id');
        $data = $request->validate([
            'group' => ['nullable', 'integer', Rule::in($corpGroups->keys()->all())],
        ]);
        $group = isset($data['group']) ? $corpGroups->get((int) $data['group']) : null;

        $moved = DB::transaction(function () use ($accountCharacter, $corpGroups, $group): int {
            $accountCharacter->launchGroups()->detach($corpGroups->keys()->all());
            if ($group === null) {
                return 0;
            }

            $others = AccountCharacter::query()
                ->where('account_id', $accountCharacter->account_id)
                ->where('id', '!=', $accountCharacter->id)
                ->pluck('id');
            $moved = $group->characters()->detach($others->all());
            $accountCharacter->launchGroups()->attach($group->id);

            return $moved;
        });

        return back()->with('success', $moved > 0
            ? 'Saved. The other character on that account was taken out of '.$group?->name.' (one per account).'
            : 'Corp launch group saved.');
    }

    /**
     * The pilot's own launch group checkboxes of one character (Main / Second / Third
     * are set with setCorpGroup and are left alone here).
     */
    public function setGroups(Request $request, AccountCharacter $accountCharacter): RedirectResponse
    {
        $user = auth()->user();
        $this->authorizeOwner($accountCharacter->account);

        $own = LaunchGroup::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id): int => (int) $id);
        $data = $request->validate([
            'groups' => ['array'],
            'groups.*' => ['integer', Rule::in($own->all())],
        ]);
        $chosen = collect($data['groups'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();

        DB::transaction(function () use ($accountCharacter, $own, $chosen): void {
            $accountCharacter->launchGroups()->detach($own->diff($chosen)->values()->all());
            $accountCharacter->launchGroups()->syncWithoutDetaching($chosen->all());
        });

        return back()->with('success', 'Launch groups saved.');
    }

    /**
     * Moves a character one place up or down inside its account.
     */
    public function moveCharacter(Request $request, AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($accountCharacter, $direction): void {
            $ordered = AccountCharacter::query()
                ->where('account_id', $accountCharacter->account_id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->values()
                ->all();

            $index = array_search($accountCharacter->id, $ordered, true);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! isset($ordered[$swapWith])) {
                return;
            }

            [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];
            foreach ($ordered as $position => $id) {
                AccountCharacter::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        return back();
    }

    public function removeCharacter(AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);
        $accountCharacter->delete();

        return back()->with('success', 'Character taken out of the account.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('devilsrage_launch_groups', 'name')->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id)),
            ],
        ], [
            'name.unique' => 'You already have a launch group with that name.',
        ]);

        if (LaunchGroup::query()->where('user_id', $user->id)->count() >= self::MAX_OWN_GROUPS) {
            return back()->withErrors(['name' => 'You can have up to '.self::MAX_OWN_GROUPS.' launch groups of your own.']);
        }

        LaunchGroup::query()->create(['user_id' => $user->id, 'name' => trim($data['name']), 'locked' => false]);

        return back()->with('success', 'Launch group "'.trim($data['name']).'" added. Tick it on the characters that belong in it.');
    }

    public function deleteGroup(LaunchGroup $launchGroup): RedirectResponse
    {
        abort_unless($launchGroup->user_id !== null && $launchGroup->user_id === (int) auth()->id(), 403);
        $launchGroup->delete();

        return back()->with('success', 'Launch group "'.$launchGroup->name.'" removed.');
    }

    public function share(Request $request): RedirectResponse
    {
        $share = $request->boolean('share');
        AccountSetting::query()->updateOrCreate(['user_id' => auth()->id()], ['share' => $share]);

        return back()->with('success', $share
            ? 'Admins and directors can now see your account layout.'
            : 'Your account layout is now private.');
    }

    public function layout(Leadership $leadership, Corporation $corporation): View
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $private = AccountSetting::query()->where('share', false)->pluck('user_id')->all();
        $accounts = Account::query()
            ->whereNotIn('user_id', $private)
            // Pilots' own launch groups are private: the corp view only shows Main / Second / Third.
            ->with(['characters.character', 'characters.launchGroups' => fn ($query) => $query->whereNull('devilsrage_launch_groups.user_id')])
            ->orderBy('nickname')
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $accounts->keys())->with('main_character')->get()->keyBy('id');
        $memberIds = $corporation->memberCharacterIds();
        $tokens = RefreshToken::query()->whereIn('character_id', $memberIds)->pluck('user_id', 'character_id');
        $links = $accounts->flatten()->flatMap(fn (Account $account) => $account->characters);
        $notGrouped = collect($memberIds)->diff($links->pluck('character_id'))->groupBy(fn (int $id): int => (int) ($tokens[$id] ?? 0));

        $groupCounts = $links
            ->flatMap(fn (AccountCharacter $link) => $link->launchGroups->pluck('name'))
            ->countBy();
        $groupCounts = LaunchGroup::query()->whereNull('user_id')->orderBy('position')->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => $groupCounts[$name] ?? 0]);

        return view('devilsrage::accounts.layout', [
            'accounts' => $accounts,
            'users' => $users,
            'groupCounts' => $groupCounts,
            'omega' => app(OmegaStatus::class)->forAccounts($accounts->flatten()),
            'privateCount' => count($private),
            'ungroupedPilots' => $notGrouped->except([0])->count(),
        ]);
    }

    /**
     * Who is in each launch group, and where an unlocked group holds two characters of
     * one account (they can't be logged in together).
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, LaunchGroup>  $groups
     * @param  Collection<int, CharacterInfo>  $characters
     * @return array<int, array{members: list<array{character: string, account: string}>, clashes: list<string>}>
     */
    private function members(Collection $accounts, Collection $groups, Collection $characters): array
    {
        $members = [];
        foreach ($groups as $group) {
            $members[$group->id] = ['members' => [], 'clashes' => []];
        }

        foreach ($accounts as $account) {
            $perGroup = [];
            foreach ($account->characters as $link) {
                foreach ($link->launchGroups as $group) {
                    if (! isset($members[$group->id])) {
                        continue;
                    }

                    $members[$group->id]['members'][] = [
                        'character' => (string) ($characters->get($link->character_id)?->name ?? $link->character_id),
                        'account' => $account->nickname,
                    ];
                    $perGroup[$group->id] = ($perGroup[$group->id] ?? 0) + 1;
                }
            }

            foreach ($perGroup as $groupId => $count) {
                if ($count > 1) {
                    $members[$groupId]['clashes'][] = $account->nickname;
                }
            }
        }

        return $members;
    }

    private function authorizeOwner(?Account $account): void
    {
        abort_unless($account !== null && (int) $account->user_id === (int) auth()->id(), 403);
    }

    private function isCharacterName(User $user, string $nickname): bool
    {
        return CharacterInfo::query()
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->pluck('name')
            ->contains(fn (string $name): bool => strcasecmp($name, $nickname) === 0);
    }
}
