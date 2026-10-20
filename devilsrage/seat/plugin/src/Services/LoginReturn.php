<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Seat\Eveapi\Models\RefreshToken;
use Throwable;

/**
 * Patch 49: SeAT's EVE login ends with redirect()->intended(), so whoever set the "intended"
 * address decides where you land. When a pilot who is already signed in adds or renews a
 * character (from anywhere: My accounts or SeAT's own Link button), they come back to My
 * accounts, and that page learns which character just logged in (to place it in an account
 * and offer the mapper step).
 */
final class LoginReturn
{
    /** Session key: the My accounts login in progress, {account: int|null}. */
    public const PENDING = 'devilsrage.login';

    /** Session key: the character that just logged in, {character_id: int, account: int|null}. */
    public const DONE = 'devilsrage.login_done';

    public static function listen(): void
    {
        RefreshToken::saved(function (RefreshToken $token): void {
            try {
                self::remember($token);
            } catch (Throwable) {
                // Never get in the way of SeAT's own login.
            }
        });
    }

    private static function remember(RefreshToken $token): void
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return;
        }
        $request = request();
        if (! $request->routeIs('seatcore::auth.eve.callback') || ! $request->hasSession()) {
            return;
        }
        $session = $request->session();
        $pending = $session->pull(self::PENDING);
        $signedIn = auth()->check();

        // Only a pilot adding a character to their own SeAT login (not someone logging in).
        if (! $signedIn && $pending === null) {
            return;
        }

        $account = is_array($pending) && isset($pending['account']) ? (int) $pending['account'] : null;
        $session->put(self::DONE, ['character_id' => (int) $token->character_id, 'account' => $account]);
        if ($signedIn && ! $session->has('url.intended')) {
            $session->put('url.intended', route('devilsrage.accounts.index'));
        }
    }
}
