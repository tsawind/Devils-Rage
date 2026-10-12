<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Enums\DiscordFeedKind;
use App\Models\DiscordAccount;
use App\Models\DiscordChannelFeed;
use App\Models\Map;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatForbiddenException;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Support\Facades\Gate;

/**
 * "/exits here", "/fuel daily" and their "stop": channels the bot posts into by itself.
 */
final readonly class ManageChannelFeedAction
{
    public const string NEEDS_MANAGE_CHANNELS = 'You need the Manage Channels permission to change what the bot posts in this channel.';

    public const string NEEDS_CHANNEL = 'Use this in a server channel, not in a DM.';

    public function __construct(private SeatClient $seat) {}

    public function start(DiscordAccount $account, DiscordFeedKind $kind, ?int $mapId, ?string $guildId, ?string $channelId, bool $canManageChannels): string
    {
        if ($guildId === null || $channelId === null) {
            return self::NEEDS_CHANNEL;
        }

        if (! $canManageChannels) {
            return self::NEEDS_MANAGE_CHANNELS;
        }

        $map = null;
        if ($kind === DiscordFeedKind::ChainExits) {
            $map = $mapId === null ? null : Map::query()->find($mapId);
            if ($map === null || Gate::forUser($account->user)->denies('view', $map)) {
                return 'That map is unavailable.';
            }
        } else {
            $denied = $this->deniedFuel($account);
            if ($denied !== null) {
                return $denied;
            }
        }

        DiscordChannelFeed::query()->updateOrCreate(
            ['kind' => $kind, 'discord_channel_id' => $channelId, 'map_id' => $map?->id],
            ['discord_guild_id' => $guildId, 'created_by_user_id' => $account->user_id],
        );

        return match ($kind) {
            DiscordFeedKind::ChainExits => sprintf('Done: every new k-space exit on **%s** gets posted here. Stop with `/exits stop`.', $map->name),
            DiscordFeedKind::FuelDaily => 'Done: the structure fuel summary gets posted here every day at 16:00 EVE time. Stop with `/fuel stop`.',
        };
    }

    public function stop(DiscordFeedKind $kind, ?int $mapId, ?string $channelId, bool $canManageChannels): string
    {
        if ($channelId === null) {
            return self::NEEDS_CHANNEL;
        }

        if (! $canManageChannels) {
            return self::NEEDS_MANAGE_CHANNELS;
        }

        $deleted = DiscordChannelFeed::query()
            ->where('kind', $kind)
            ->where('discord_channel_id', $channelId)
            ->when($mapId !== null, fn ($query) => $query->where('map_id', $mapId))
            ->delete();

        return $deleted === 0
            ? sprintf('Nothing was posting "%s" in this channel.', $kind->label())
            : sprintf('Stopped "%s" in this channel.', $kind->label());
    }

    private function deniedFuel(DiscordAccount $account): ?string
    {
        try {
            return $this->seat->fuel($account->discord_user_id) === null ? SeatMessages::NOT_LINKED : null;
        } catch (SeatForbiddenException $exception) {
            return $exception->getMessage();
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }
    }
}
