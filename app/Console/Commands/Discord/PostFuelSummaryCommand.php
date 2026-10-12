<?php

declare(strict_types=1);

namespace App\Console\Commands\Discord;

use App\Actions\Discord\ShowStructureFuelAction;
use App\Enums\DiscordFeedKind;
use App\Models\DiscordChannelFeed;
use App\Services\Discord\DiscordHttp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('discord:post-fuel {--weekly : The Friday post for channels set up with /fuel weekly}')]
#[Description('Post the structure fuel summary into every channel set up with /fuel daily (or /fuel weekly).')]
final class PostFuelSummaryCommand extends Command
{
    public function handle(ShowStructureFuelAction $fuel, DiscordHttp $http): int
    {
        $feeds = DiscordChannelFeed::query()
            ->where('kind', $this->option('weekly') ? DiscordFeedKind::FuelWeekly : DiscordFeedKind::FuelDaily)
            ->with('creator.discordAccount')
            ->get();

        foreach ($feeds as $feed) {
            $discordUserId = $feed->creator->discordAccount?->discord_user_id;
            if (! is_string($discordUserId) || $discordUserId === '') {
                continue;
            }

            try {
                $content = $fuel->summary($discordUserId);
                $http->bot()->post('/channels/'.$feed->discord_channel_id.'/messages', [
                    'content' => mb_substr($content, 0, 1950),
                    'allowed_mentions' => ['parse' => []],
                ])->throw();
                $feed->update(['last_posted_at' => now()]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return self::SUCCESS;
    }
}
