<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Middleware;

use Closure;
use DevilsRage\Seat\Srp\KillLinks;
use DevilsRage\Seat\Srp\KillResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patch 54: the SRP plugin reads the last two parts of the pasted link as kill id and hash, so a
 * zKillboard link (no hash), an ESI link ending in ?datasource=… or an in-game kill link failed.
 * Before its "read the killmail" step, any of those is turned into the plain ESI link it expects.
 * Anything else (a battle link, say) is refused before SRP saves it as a killmail with a made-up hash.
 */
class NormalizeSrpLink
{
    private const array ROUTES = ['srp.getKillMail', 'srp.getKillMailQuote'];

    public function handle(Request $request, Closure $next): Response
    {
        $link = $request->query('km');
        if (is_string($link) && in_array($request->route()?->getName(), self::ROUTES, true)) {
            $kill = KillLinks::kill($link);
            $hash = $kill === null ? null : ($kill['hash'] ?? app(KillResolver::class)->hash($kill['id']));
            if ($kill === null || $hash === null) {
                // Passing it on would save a made-up killmail that SeAT then retries against ESI all day.
                return response()->json(['error' => KillLinks::battle($link) !== null
                    ? 'That is a battle link: use Devil\'s Rage > SRP a battle for it.'
                    : 'That is not a kill link SRP can read (ESI, zKillboard kill or in-game kill link).'], 422);
            }
            $request->merge(['km' => KillLinks::esiUrl($kill['id'], $hash)]);
        }

        return $next($request);
    }
}
