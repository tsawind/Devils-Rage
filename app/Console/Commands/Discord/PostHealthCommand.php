<?php

declare(strict_types=1);

namespace App\Console\Commands\Discord;

use App\Actions\Discord\ShowHealthAction;
use App\Enums\DiscordFeedKind;
use App\Models\DiscordChannelFeed;
use App\Services\Discord\DiscordHttp;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('discord:post-health {--always : Post even when everything is fine}')]
#[Description('Post SeAT health problems into every channel set up with /health daily ("all good" on Fridays).')]
final class PostHealthCommand extends Command
{
    public function handle(ShowHealthAction $health, DiscordHttp $http): int
    {
        $always = $this->option('always') || Carbon::now()->isFriday();
        $feeds = DiscordChannelFeed::query()
            ->where('kind', DiscordFeedKind::Health)
            ->with('creator.discordAccount')
            ->get();

        foreach ($feeds as $feed) {
            $discordUserId = $feed->creator->discordAccount?->discord_user_id;
            if (! is_string($discordUserId) || $discordUserId === '') {
                continue;
            }

            try {
                $report = $health->report($discordUserId);
            } catch (SeatUnavailableException $exception) {
                $report = ['text' => '⚠️ **SeAT health**: SeAT could not be asked: '.$exception->getMessage(), 'problems' => 1];
            }

            if ($report['problems'] === 0 && ! $always) {
                continue;
            }

            try {
                $http->bot()->post('/channels/'.$feed->discord_channel_id.'/messages', [
                    'content' => mb_substr($report['text'], 0, 1950),
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
