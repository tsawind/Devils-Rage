<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Controllers;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Models\AccountCharacter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;

/**
 * Group Your accounts from the EVE launcher's logs on the member's PC: the app reads which
 * characters the launcher loaded for each EVE account, keeps only characters this SeAT knows
 * for the member (mine.json), and sends those groups here.
 */
class LauncherAccountsController extends Controller
{
    /** The member's own character IDs, so the app never sends characters SeAT doesn't know. */
    public function mine(): JsonResponse
    {
        return response()->json(['character_ids' => array_map('intval', auth()->user()->associatedCharacterIds())]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accounts' => ['required', 'array', 'max:200'],
            'accounts.*.user_id' => ['required', 'integer', 'min:1'],
            'accounts.*.character_ids' => ['required', 'array', 'max:3'],
            'accounts.*.character_ids.*' => ['integer'],
        ]);
        $user = auth()->user();
        $mine = array_map('intval', $user->associatedCharacterIds());
        $created = 0;
        $moved = 0;
        DB::transaction(function () use ($data, $user, $mine, &$created, &$moved): void {
            foreach ($data['accounts'] as $group) {
                $ids = array_values(array_intersect(array_map('intval', $group['character_ids']), $mine));
                if ($ids === []) {
                    continue;
                }
                $links = AccountCharacter::query()->whereIn('character_id', $ids)->with('account')->get()
                    ->filter(fn (AccountCharacter $l) => (int) $l->account->user_id === (int) $user->id);
                // The account already matched to this launcher account, else the one holding most of them, else a new one.
                $account = Account::query()->where('user_id', $user->id)->where('launcher_user_id', $group['user_id'])->first()
                    ?? $links->groupBy('account_id')->sortByDesc(fn ($l) => $l->count())->first()?->first()?->account;
                if ($account === null) {
                    $nickname = 'Account '.$group['user_id'];
                    $account = Account::query()->firstOrCreate(['user_id' => $user->id, 'nickname' => $nickname]);
                    $created++;
                }
                Account::query()->where('user_id', $user->id)->where('launcher_user_id', $group['user_id'])->whereKeyNot($account->id)->update(['launcher_user_id' => null]);
                $account->update(['launcher_user_id' => $group['user_id']]);
                foreach ($ids as $characterId) {
                    $link = $links->firstWhere('character_id', $characterId);
                    if ($link !== null && (int) $link->account_id === (int) $account->id) {
                        continue;
                    }
                    if (AccountCharacter::query()->where('account_id', $account->id)->count() >= Account::MAX_CHARACTERS) {
                        continue;
                    }
                    if ($link !== null) {
                        $link->update(['account_id' => $account->id, 'position' => (int) AccountCharacter::query()->where('account_id', $account->id)->max('position') + 1]);
                    } elseif (! AccountCharacter::query()->where('character_id', $characterId)->exists()) {
                        $account->characters()->create(['character_id' => $characterId, 'position' => (int) $account->characters()->max('position') + 1]);
                    }
                    $moved++;
                }
            }
        });

        return response()->json(['message' => "Your accounts: {$moved} characters placed, {$created} new accounts (rename them on Your accounts).", 'moved' => $moved, 'created' => $created]);
    }
}
