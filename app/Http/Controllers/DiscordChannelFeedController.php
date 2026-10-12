<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DiscordChannelFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class DiscordChannelFeedController extends Controller
{
    /**
     * Map managers can stop a map's chain-exit posts from the settings page
     * (the daily fuel post isn't tied to a map: stop it with /fuel stop).
     */
    public function destroy(DiscordChannelFeed $discordChannelFeed): RedirectResponse
    {
        abort_if($discordChannelFeed->map === null, 404);
        Gate::authorize('manageAccess', $discordChannelFeed->map);

        $discordChannelFeed->delete();

        return back()->notify('Feed stopped', message: 'The bot no longer posts new exits in that channel.');
    }
}
