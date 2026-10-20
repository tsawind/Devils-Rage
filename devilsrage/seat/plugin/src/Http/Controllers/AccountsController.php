<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use CryptaTech\Seat\Fitting\Models\Fitting;
use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\LaunchGroup;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use DevilsRage\Seat\Services\LoginReturn;
use DevilsRage\Seat\Services\LoginStatus;
use DevilsRage\Seat\Services\Holdings;
use DevilsRage\Seat\Services\OmegaStatus;
use DevilsRage\Seat\Services\PersonName;
use DevilsRage\Seat\Services\PublicCharacter;
use DevilsRage\Seat\Services\ViewMode;
use Illuminate\Http\JsonResponse;
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

    public function index(LoginStatus $logins): View
    {
        $user = auth()->user();
        $justLoggedIn = $this->justLoggedIn($user);
        $characters = CharacterInfo::query()
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->orderBy('name')
            ->get()
            ->keyBy('character_id');

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['characters.launchGroups', 'characters.character'])
            ->ordered()
            ->get();

        $groups = LaunchGroup::forUser($user->id);
        $omega = app(OmegaStatus::class);
        $assigned = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->all();
        $holdings = app(Holdings::class);

        // Patch 49: whether each character's SeAT and mapper logins work.
        [$seatStatus, $mapperStatus] = $this->loginData($logins, $accounts, $characters);

        return view('devilsrage::accounts.index', [
            'seatStatus' => $seatStatus,
            'mapperStatus' => $mapperStatus,
            'mainStatus' => $seatStatus[(int) $user->main_character_id] ?? null,
            'justLoggedIn' => $justLoggedIn,
            'mapperUrl' => rtrim((string) config('devilsrage.mapper_url', ''), '/'),
            'accounts' => $accounts,
            'characters' => $characters,
            'corpGroups' => $groups->where('locked', true)->values(),
            'ownGroups' => $groups->where('locked', false)->values(),
            'members' => $this->members($accounts, $groups, $characters),
            'omega' => $omega->forAccounts($accounts),
            'holdings' => $holdings->accounts($accounts, $holdings->characters(array_map('intval', $assigned))),
            'unassigned' => $characters->except($assigned)->values(),
            'share' => AccountSetting::sharesFor($user->id),
            'personSetting' => AccountSetting::query()->find($user->id),
            'mainName' => PersonName::mainName($user),
            'maxCharacters' => Account::MAX_CHARACTERS,
            'fittings' => Fitting::query()->with('ship')->orderBy('name')->get()
                ->mapWithKeys(fn (Fitting $fit): array => [(int) $fit->fitting_id => $fit->name.' ('.($fit->ship->typeName ?? '?').')']),
            'externalFits' => DB::table(AccountCharacter::EXTERNAL_FITS)
                ->whereIn('account_character_id', $accounts->flatMap(fn (Account $account) => $account->characters->where('external', true)->pluck('id'))->all())
                ->get()
                ->groupBy('account_character_id')
                ->map(fn ($rows) => $rows->pluck('fitting_id')->map(fn ($id): int => (int) $id)->all()),
        ]);
    }

    /**
     * Patch 49: "Log in this account" / "Log in a character": EVE's login, then back to this
     * account (a new character goes into its empty slot).
     * Patch 49b: ?public=1 asks EVE for public data only (proves the character is yours, shares
     * nothing else; for alts whose other group doesn't allow sharing). ?screen=1 = started from
     * the app's Your accounts screen, which opened the login in a small window: it comes back
     * there and that window closes itself.
     */
    public function login(Request $request, ?Account $account = null): RedirectResponse
    {
        if ($account !== null) {
            $this->authorizeOwner($account);
        }
        $public = $request->boolean('public');
        session()->put(LoginReturn::PENDING, ['account' => $account?->id, 'public' => $public]);
        session()->put('url.intended', $request->boolean('screen')
            ? route('devilsrage.your-accounts', ['done' => 1])
            : route('devilsrage.accounts.index').($account ? '#acct-'.$account->id : ''));

        if (! $public) {
            return redirect()->route('seatcore::auth.eve');
        }

        // SeAT's own login always copies the main's permissions to a new character; a
        // public-only login goes to EVE directly with publicData and comes back the same way.
        session()->put('scopes', ['publicData']);
        session()->put('scopes_profile_id', 0);

        return \Laravel\Socialite\Facades\Socialite::driver('eveonline')->scopes(['publicData'])->redirect();
    }

    /**
     * Patch 49: SeAT gives every newly added character the permissions your MAIN's login has.
     * When the main's login misses some, it is renewed by logging in with the main afresh
     * (signed out, so SeAT asks EVE for the full set), then you're back on My accounts.
     */
    public function loginMain(): RedirectResponse
    {
        auth()->logout();
        session()->put(LoginReturn::PENDING, ['account' => null]);
        session()->put('url.intended', route('devilsrage.accounts.index'));

        return redirect()->route('seatcore::auth.eve');
    }

    /**
     * Patch 49: the character that just logged in (if any): put it in the account it was logged
     * in for when it isn't in one yet, and say what's next.
     *
     * @return array{character_id: int, name: string, placed: string|null, public: bool}|null
     */
    private function justLoggedIn(User $user): ?array
    {
        $done = session()->pull(LoginReturn::DONE);
        if (! is_array($done) || empty($done['character_id'])) {
            return null;
        }
        $characterId = (int) $done['character_id'];
        if (! in_array($characterId, array_map('intval', $user->associatedCharacterIds()), true)) {
            return null;
        }

        $placed = null;
        $public = ! empty($done['public']);
        $account = isset($done['account']) ? Account::query()->where('user_id', $user->id)->find((int) $done['account']) : null;
        $already = AccountCharacter::query()->where('character_id', $characterId)->first();
        if ($already !== null) {
            $placed = $already->account?->nickname;
            // A full login upgrades a public-only character; a public one marks it.
            $already->update(['public_only' => $public, 'external' => false]);
        } elseif ($account !== null && $account->characters()->count() < Account::MAX_CHARACTERS) {
            $account->characters()->create([
                'character_id' => $characterId,
                'position' => (int) $account->characters()->max('position') + 1,
                'public_only' => $public,
            ]);
            $placed = $account->nickname;
        }

        $name = CharacterInfo::query()->where('character_id', $characterId)->value('name') ?? 'Your character';

        return ['character_id' => $characterId, 'name' => (string) $name, 'placed' => $placed, 'public' => $public];
    }

    /**
     * Patch 49: SeAT and mapper login state for every character on the page.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int|string, CharacterInfo>  $characters
     * @return array{0: array<int, string>, 1: array<int, bool>|null}
     */
    private function loginData(LoginStatus $logins, Collection $accounts, Collection $characters): array
    {
        $links = $accounts->flatMap(fn (Account $account) => $account->characters->where('external', false));
        $ids = array_values(array_unique(array_merge(
            array_map('intval', $characters->keys()->all()),
            $links->pluck('character_id')->map(fn ($id): int => (int) $id)->all(),
        )));
        $public = $links->where('public_only', true)->pluck('character_id')->map(fn ($id): int => (int) $id)->values()->all();

        return [$logins->seat($ids, $public), $logins->mapper($ids, $public)];
    }

    /**
     * Patch 49b: the app's "Your accounts" screen (also works in a browser): every account
     * like the EVE launcher, logins fixed one EVE account at a time, accounts and characters
     * dragged into order. The same data as My accounts.
     */
    public function screen(LoginStatus $logins): View
    {
        $user = auth()->user();
        $justLoggedIn = $this->justLoggedIn($user);
        $characters = CharacterInfo::query()
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->orderBy('name')
            ->get()
            ->keyBy('character_id');
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['characters.launchGroups', 'characters.character'])
            ->ordered()
            ->get();
        $assigned = $accounts->flatMap(fn (Account $account) => $account->characters->pluck('character_id'))->all();
        [$seatStatus, $mapperStatus] = $this->loginData($logins, $accounts, $characters);

        return view('devilsrage::accounts.screen', [
            'accounts' => $accounts,
            'unassigned' => $characters->except($assigned)->values(),
            'corpGroups' => LaunchGroup::forUser($user->id)->where('locked', true)->values(),
            'omega' => app(OmegaStatus::class)->forAccounts($accounts),
            'seatStatus' => $seatStatus,
            'mapperStatus' => $mapperStatus,
            'mainStatus' => $seatStatus[(int) $user->main_character_id] ?? null,
            'justLoggedIn' => $justLoggedIn,
            'mapperUrl' => rtrim((string) config('devilsrage.mapper_url', ''), '/'),
            'maxCharacters' => Account::MAX_CHARACTERS,
            'done' => request()->boolean('done'),
        ]);
    }

    /**
     * Patch 49b: the order of your accounts, as dragged (account ids, first to last).
     */
    public function orderAccounts(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']]);
        $mine = Account::query()->where('user_id', auth()->id())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $position = 0;
        foreach ($data['ids'] as $id) {
            if (in_array((int) $id, $mine, true)) {
                Account::query()->whereKey((int) $id)->update(['position' => ++$position]);
            }
        }

        return response()->json(['saved' => $position]);
    }

    /**
     * Patch 49b: the order of one account's characters, as dragged (account character ids).
     */
    public function orderCharacters(Request $request, Account $account): JsonResponse
    {
        $this->authorizeOwner($account);
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.Account::MAX_CHARACTERS], 'ids.*' => ['integer']]);
        $mine = $account->characters()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $position = 0;
        foreach ($data['ids'] as $id) {
            if (in_array((int) $id, $mine, true)) {
                AccountCharacter::query()->whereKey((int) $id)->update(['position' => ++$position]);
            }
        }

        return response()->json(['saved' => $position]);
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
     * A character that isn't registered on our SeAT (e.g. a dual-corp pilot's other
     * group): public data only, its corp fits ticked by hand.
     */
    public function addExternal(Request $request, Account $account, PublicCharacter $lookup): RedirectResponse
    {
        $this->authorizeOwner($account);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:37'],
        ]);

        if ($account->characters()->count() >= Account::MAX_CHARACTERS) {
            return back()->withErrors(['name' => 'An EVE account holds at most '.Account::MAX_CHARACTERS.' characters.']);
        }

        $found = $lookup->find($data['name']);
        if ($found === null) {
            return back()->withErrors(['name' => 'EVE knows no character called "'.$data['name'].'" (check the spelling, it must be exact).']);
        }

        if (RefreshToken::withTrashed()->where('character_id', $found['id'])->exists()) {
            return back()->withErrors(['name' => $found['name'].' is registered on SeAT: add it from the character list instead (or log it in again if its token expired).']);
        }

        if (AccountCharacter::query()->where('character_id', $found['id'])->exists()) {
            return back()->withErrors(['name' => $found['name'].' is already in an account.']);
        }

        $account->characters()->create([
            'character_id' => $found['id'],
            'position' => (int) $account->characters()->max('position') + 1,
            'external' => true,
            'external_name' => $found['name'],
            'external_corporation' => $found['corporation'],
            'external_alliance' => $found['alliance'],
        ]);

        return back()->with('success', $found['name'].' added (not on SeAT: tick the corp fits it can fly under ⚙).');
    }

    /**
     * Corp fits an external character can fly, as the pilot says.
     */
    public function setExternalFits(Request $request, AccountCharacter $accountCharacter): RedirectResponse
    {
        $this->authorizeOwner($accountCharacter->account);
        abort_unless($accountCharacter->external, 404);

        $data = $request->validate([
            'fits' => ['array'],
            'fits.*' => ['integer'],
        ]);
        $fitIds = Fitting::query()->whereIn('fitting_id', $data['fits'] ?? [])->pluck('fitting_id');

        DB::transaction(function () use ($accountCharacter, $fitIds): void {
            DB::table(AccountCharacter::EXTERNAL_FITS)->where('account_character_id', $accountCharacter->id)->delete();
            DB::table(AccountCharacter::EXTERNAL_FITS)->insert($fitIds->unique()->map(fn ($id): array => [
                'account_character_id' => $accountCharacter->id,
                'fitting_id' => (int) $id,
            ])->values()->all());
        });

        return back()->with('success', 'Fits saved for '.$accountCharacter->displayName().'.');
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

    /**
     * Patch 39: the name you go by on the mapper and Discord, and how Discord shows you.
     */
    public function saveName(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'person_name' => ['nullable', 'string', 'max:'.PersonName::MAX],
            'discord_style' => ['required', 'in:'.implode(',', PersonName::STYLES)],
        ]);
        $name = trim((string) ($validated['person_name'] ?? ''));
        $problem = PersonName::problem($name, (int) auth()->id());
        if ($problem !== null) {
            return back()->withErrors(['person_name' => $problem])->withInput();
        }

        AccountSetting::query()->updateOrCreate(['user_id' => auth()->id()], [
            'person_name' => $name === '' ? null : $name,
            'discord_style' => $validated['discord_style'],
        ]);

        return back()->with('success', $name === ''
            ? 'You go by your main\'s name. Discord updates within the hour.'
            : "You go by {$name} on the mapper. Discord updates within the hour.");
    }

    public function layout(Leadership $leadership, Corporation $corporation): View
    {
        abort_unless($leadership->isLeader(auth()->user()), 403);

        $private = AccountSetting::query()->where('share', false)->pluck('user_id')->all();
        $accounts = Account::query()
            ->whereNotIn('user_id', $private)
            // Patch 39: "My characters only" shows just your own accounts here too.
            ->when(ViewMode::ownOnly(), fn ($query) => $query->where('user_id', auth()->id()))
            // Pilots' own launch groups are private: the corp view only shows Main / Second / Third.
            ->with(['characters.character', 'characters.launchGroups' => fn ($query) => $query->whereNull('devilsrage_launch_groups.user_id')])
            ->ordered()
            ->get()
            ->groupBy('user_id');

        $users = User::query()->whereIn('id', $accounts->keys())->with('main_character')->get()->keyBy('id');
        $memberIds = ViewMode::narrow($corporation->memberCharacterIds(), auth()->user());
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
            'inCorp' => array_flip($memberIds),
            'withToken' => RefreshToken::query()->whereIn('character_id', $links->pluck('character_id')->all())->pluck('character_id')->flip()->all(),
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
                        'character' => $characters->get($link->character_id)?->name ?? $link->displayName(),
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
