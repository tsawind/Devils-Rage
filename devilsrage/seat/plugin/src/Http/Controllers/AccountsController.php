<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\User;

/**
 * "My accounts": a pilot groups their characters into EVE accounts (nickname, max 3
 * characters) and puts them in launch groups. "Corp layout": leaders see everyone who shares.
 */
class AccountsController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $characterIds = $user->associatedCharacterIds();
        $characters = CharacterInfo::query()->whereIn('character_id', $characterIds)->orderBy('name')->get()->keyBy('character_id');

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('characters')
            ->orderBy('nickname')
            ->get();

        $assigned = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->all();

        $groupMembers = [];
        foreach (array_keys(AccountCharacter::GROUPS) as $group) {
            $groupMembers[$group] = [];
            foreach ($accounts as $account) {
                foreach ($account->characters->where('launch_group', $group) as $link) {
                    $groupMembers[$group][] = [
                        'character' => (string) ($characters->get($link->character_id)?->name ?? $link->character_id),
                        'account' => $account->nickname,
                    ];
                }
            }
        }

        return view('devilsrage::accounts.index', [
            'accounts' => $accounts,
            'groupMembers' => $groupMembers,
            'characters' => $characters,
            'unassigned' => $characters->except($assigned)->values(),
            'share' => AccountSetting::sharesFor($user->id),
            'groups' => AccountCharacter::GROUPS,
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

        $account->characters()->create(['character_id' => (int) $data['character_id']]);

        return back()->with('success', 'Character added.');
    }

    public function updateCharacter(Request $request, AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);
        $data = $request->validate([
            'launch_group' => ['nullable', Rule::in(array_keys(AccountCharacter::GROUPS))],
        ]);
        $group = $data['launch_group'] ?? null;

        $moved = DB::transaction(function () use ($accountCharacter, $group): int {
            $moved = 0;
            if ($group !== null) {
                // One character per account per launch group: EVE logs in one character per account.
                $moved = AccountCharacter::query()
                    ->where('account_id', $accountCharacter->account_id)
                    ->where('id', '!=', $accountCharacter->id)
                    ->where('launch_group', $group)
                    ->update(['launch_group' => null]);
            }
            $accountCharacter->update(['launch_group' => $group]);

            return $moved;
        });

        return back()->with('success', $moved > 0
            ? 'Launch group set. The other character on that account was taken out of '.AccountCharacter::GROUPS[$group].' (one per account).'
            : 'Launch group saved.');
    }

    public function removeCharacter(AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);
        $accountCharacter->delete();

        return back()->with('success', 'Character taken out of the account.');
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
            ->with(['characters.character'])
            ->orderBy('nickname')
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $accounts->keys())->with('main_character')->get()->keyBy('id');
        $memberIds = $corporation->memberCharacterIds();
        $tokens = RefreshToken::query()->whereIn('character_id', $memberIds)->pluck('user_id', 'character_id');
        $inAccounts = $accounts->flatten()->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->all();
        $notGrouped = collect($memberIds)->diff($inAccounts)->groupBy(fn (int $id): int => (int) ($tokens[$id] ?? 0));

        $groupCounts = [];
        foreach (array_keys(AccountCharacter::GROUPS) as $group) {
            $groupCounts[$group] = $accounts->flatten()->sum(fn (Account $account): int => $account->characters->where('launch_group', $group)->count());
        }

        return view('devilsrage::accounts.layout', [
            'accounts' => $accounts,
            'users' => $users,
            'groups' => AccountCharacter::GROUPS,
            'groupCounts' => $groupCounts,
            'privateCount' => count($private),
            'ungroupedPilots' => $notGrouped->except([0])->count(),
        ]);
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
