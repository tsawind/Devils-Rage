<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MapShareLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patch 37: the route page behind a mailed route report's link: the routes as they were
 * when the mail was sent (systems, holes, sizes, EOL times), and nothing else of the map.
 * Works for 24 h without logging in.
 */
final class RouteShareController extends Controller
{
    public function show(string $token): Response
    {
        $link = MapShareLink::query()->where('token', $token)->where('kind', 'route')->live()->firstOrFail();

        $link->forceFill(['last_used_at' => now(), 'uses' => $link->uses + 1])->save();

        return Inertia::render('share/RouteShare', [
            'report' => $link->payload,
            'expires_at' => $link->expires_at?->toISOString(),
        ]);
    }
}
