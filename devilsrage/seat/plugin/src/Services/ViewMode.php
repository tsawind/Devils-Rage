<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\AccountCharacter;
use Seat\Web\Models\User;

/**
 * Patch 39: how the Devil's Rage pages are being looked at, for this login session.
 *  - "My characters only" (session dr_own_only, the top-bar toggle): lists only show the
 *    viewer's own characters, on every Devil's Rage page too.
 *  - "View as member" (session dr_view_as_member, leaders only): drops your leader rights
 *    on these pages (corp member view, your own characters) until you switch back. Safer
 *    than impersonating a real pilot; SeAT's own Impersonate stays as it is.
 * Both only ever narrow what is shown.
 */
final class ViewMode
{
    public const OWN_ONLY = 'dr_own_only';

    public const AS_MEMBER = 'dr_view_as_member';

    public static function asMember(): bool
    {
        return (bool) session(self::AS_MEMBER, false);
    }

    public static function ownOnly(): bool
    {
        return self::asMember() || (bool) session(self::OWN_ONLY, false);
    }

    /**
     * The characters a list may show: all of them, or (own-only) just the viewer's.
     *
     * @param  list<int>  $characterIds
     * @return list<int>
     */
    public static function narrow(array $characterIds, ?User $user): array
    {
        if (! self::ownOnly() || $user === null) {
            return array_values(array_map('intval', $characterIds));
        }

        $own = array_flip(self::ownCharacterIds($user));

        return array_values(array_filter(array_map('intval', $characterIds), fn (int $id): bool => isset($own[$id])));
    }

    /**
     * The viewer's own characters: SeAT logins plus characters in their own accounts.
     *
     * @return list<int>
     */
    public static function ownCharacterIds(User $user): array
    {
        $ids = array_map('intval', $user->associatedCharacterIds());
        $accountIds = AccountCharacter::query()
            ->whereHas('account', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$accountIds]));
    }
}
