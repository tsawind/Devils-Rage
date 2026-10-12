<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bot API only answers requests carrying DR_BOT_API_KEY in the X-DR-Key header.
 * Without a key configured the API does not exist (404).
 */
class BotApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('devilsrage.bot_api_key', '');
        if (strlen($key) < 32) {
            abort(404);
        }

        if (! hash_equals($key, (string) $request->header('X-DR-Key', ''))) {
            abort(401);
        }

        return $next($request);
    }
}
