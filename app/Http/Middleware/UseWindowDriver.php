<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patch 39: each browser window or tab has its own main ("driver"), sent with every
 * request (X-Map-Driver header, or ?as= on a page load). The request then acts as that
 * character when it is one of the user's own; the login's session main is left alone, so
 * windows never switch each other's main.
 */
final class UseWindowDriver
{
    public const string HEADER = 'X-Map-Driver';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $driver = $request->header(self::HEADER) ?? $request->query('as');

        if ($user instanceof User && is_numeric($driver)) {
            $character = $user->characters->firstWhere('id', (int) $driver);
            if ($character !== null) {
                $user->driveAs($character);
            }
        }

        return $next($request);
    }
}
