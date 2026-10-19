<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAppConfigRequest;
use App\Models\User;
use App\Services\DesktopApp\DesktopAppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patch 46: the desktop app's settings for everyone. The app reads them without a login
 * (show); directors change them on the App admin page (edit / update).
 */
final class AppConfigController extends Controller
{
    public function __construct(private readonly DesktopAppConfig $config) {}

    public function show(): JsonResponse
    {
        $current = $this->config->current();

        return response()->json([
            'motd' => $current['motd'],
            'min_version' => $current['min_version'],
            'features' => $current['features'],
        ]);
    }

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isAppAdmin(), 403, 'Only the app admins set in the mapper (APP_ADMIN_CHARACTER_IDS) can open this page.');

        return Inertia::render('app/Admin', [
            'config' => $this->config->current(),
            'features' => DesktopAppConfig::FEATURES,
        ]);
    }

    public function update(UpdateAppConfigRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, bool> $features */
        $features = array_map(boolval(...), (array) $request->validated('features', []));

        $this->config->update(
            $request->validated('motd'),
            $request->validated('min_version'),
            $features,
            $user->active_character->name ?? $user->name,
        );

        return back();
    }
}
