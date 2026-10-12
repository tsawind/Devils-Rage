<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Middleware;

use Closure;
use DevilsRage\Seat\Http\Controllers\HomeController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends pilots from SeAT's home page to the Devil's Rage home, unless they switched
 * that off on Devil's Rage → Arrange menu (or opened SeAT home with ?seat=1).
 */
class StartPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null
            && $request->isMethod('GET')
            && $request->route()?->getName() === 'seatcore::home'
            && ! $request->has('seat')
            && HomeController::startsOnHome((int) $user->id)) {
            return redirect()->route('devilsrage.home');
        }

        return $next($request);
    }
}
