<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Enums\JumpShipType;
use App\Enums\MapAlertDeliveryType;
use App\Enums\MapAlertMentionMode;
use App\Enums\MapAlertType;
use App\Models\DiscordAccount;
use App\Models\Map;
use App\Models\MapAlert;
use App\Models\Solarsystem;
use App\Services\MapAlerts\MapAlertLifecycle;
use App\Services\Routing\MapProximityPathfinder;
use App\Services\Routing\ProximityResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class CreateDiscordAlertAction
{
    public function __construct(private MapAlertLifecycle $lifecycle) {}

    public function handle(
        DiscordAccount $account,
        MapAlertType $type,
        ?MapAlertDeliveryType $delivery,
        int $mapId,
        ?int $solarsystemId,
        ?int $jumps,
        ?JumpShipType $shipType,
        ?int $jdcLevel,
        bool $includeHighsec,
        ?MapAlertMentionMode $mentionMode,
        ?string $guildId,
        ?string $channelId,
        ?string $roleId,
        ?int $originSolarsystemId = null,
        bool $xlOnly = false,
    ): string {
        $map = Map::query()->find($mapId);
        if ($map === null) {
            return 'Pick the map from the list (start typing its name, e.g. "Daisy", and click it).';
        }
        if (! in_array($delivery, [MapAlertDeliveryType::DiscordDm, MapAlertDeliveryType::DiscordChannel], true)) {
            return 'Pick where the alert goes: `/alert dm …` or `/alert channel …`.';
        }
        if (! $mentionMode instanceof MapAlertMentionMode) {
            return 'Pick a mention from the list (None, @here, @everyone or A role).';
        }

        $needsTarget = $type !== MapAlertType::Killmail;
        $target = $needsTarget && $solarsystemId !== null ? Solarsystem::query()->find($solarsystemId) : null;
        if ($needsTarget && $target === null) {
            return 'Pick the target system from the list (start typing its name or J-code and click it).';
        }

        if ($originSolarsystemId !== null && $type !== MapAlertType::Proximity) {
            return 'Starting points are only supported for proximity alerts.';
        }

        $origin = $originSolarsystemId === null ? null : Solarsystem::query()->find($originSolarsystemId);
        if ($originSolarsystemId !== null && ! $origin instanceof Solarsystem) {
            return 'Pick the starting system from the list (start typing its name and click it).';
        }

        $needsJumps = $type !== MapAlertType::JumpRange;
        if ($needsJumps && ($jumps === null || $jumps < 1 || $jumps > 20)) {
            return 'The jump count must be between 1 and 20.';
        }

        if ($type === MapAlertType::JumpRange && (! $shipType instanceof JumpShipType || $jdcLevel === null || $jdcLevel < 1 || $jdcLevel > 5)) {
            return 'Jump-range alerts need a ship class and a calibration level between 1 and 5.';
        }

        $isChannel = $delivery === MapAlertDeliveryType::DiscordChannel;

        if (Gate::forUser($account->user)->denies($isChannel ? 'manageAccess' : 'view', $map)) {
            return $isChannel
                ? 'Only map managers can create channel alerts.'
                : 'You no longer have access to that map.';
        }

        if ($isChannel && (blank($guildId) || blank($channelId))) {
            return 'Channel alerts must be created inside a server channel.';
        }

        if (! $isChannel && ($mentionMode !== MapAlertMentionMode::None || filled($roleId))) {
            return 'Mention options can only be used for channel alerts.';
        }

        if (($mentionMode === MapAlertMentionMode::Role) !== filled($roleId)) {
            return 'Select a role only when the mention option is A role.';
        }

        $includeHighsec = $includeHighsec && $shipType?->canEnterHighsec() === true;

        // The same alert twice only means the same ping twice.
        $duplicate = MapAlert::query()
            ->where('map_id', $map->id)
            ->where('created_by_user_id', $account->user_id)
            ->where('delivery_type', $delivery)
            ->where('type', $type)
            ->where('target_solarsystem_id', $target?->id)
            ->where('origin_solarsystem_id', $origin?->id)
            ->where('max_jumps', $type === MapAlertType::JumpRange ? null : $jumps)
            ->where('ship_type', $type === MapAlertType::JumpRange ? $shipType : null)
            ->where('jdc_level', $type === MapAlertType::JumpRange ? $jdcLevel : null)
            ->where('include_highsec', $type === MapAlertType::JumpRange && $includeHighsec)
            ->where('xl_only', $type === MapAlertType::JumpRange && $xlOnly)
            ->where('discord_channel_id', $isChannel ? $channelId : null)
            ->first();
        if ($duplicate instanceof MapAlert) {
            return sprintf(
                'You already have this alert (#%d%s). Nothing added.%s',
                $duplicate->id,
                $duplicate->is_active ? '' : ', switched off',
                $duplicate->is_active ? '' : ' Turn it back on with `/alerts enable`.',
            );
        }

        DB::transaction(function () use ($account, $map, $type, $target, $origin, $jumps, $shipType, $jdcLevel, $includeHighsec, $xlOnly, $delivery, $mentionMode, $guildId, $channelId, $roleId, $isChannel): void {
            $alert = MapAlert::query()->create([
                'map_id' => $map->id,
                'created_by_user_id' => $account->user_id,
                'delivery_type' => $delivery,
                'map_webhook_id' => null,
                'map_webhook_role_id' => null,
                'mention_mode' => $isChannel ? $mentionMode : MapAlertMentionMode::None,
                'discord_guild_id' => $isChannel ? $guildId : null,
                'discord_channel_id' => $isChannel ? $channelId : null,
                'discord_role_id' => $isChannel ? $roleId : null,
                'type' => $type,
                'target_solarsystem_id' => $target?->id,
                'origin_solarsystem_id' => $origin?->id,
                'max_jumps' => $type === MapAlertType::JumpRange ? null : $jumps,
                'ship_type' => $type === MapAlertType::JumpRange ? $shipType : null,
                'jdc_level' => $type === MapAlertType::JumpRange ? $jdcLevel : null,
                'include_highsec' => $type === MapAlertType::JumpRange && $includeHighsec,
                'xl_only' => $type === MapAlertType::JumpRange && $xlOnly,
                'is_active' => true,
            ]);
            $this->lifecycle->created($alert, $account->user);
        });

        return match ($type) {
            MapAlertType::Proximity => ($origin instanceof Solarsystem
                ? sprintf('Alert created for **%s** within %s of **%s** through the **%s** chain.', $target->name, $this->jumpsText((int) $jumps), $origin->name, $map->name)
                : sprintf('Alert created for **%s** within %s of **%s**.', $target->name, $this->jumpsText((int) $jumps), $map->name))
                .$this->inRangeNow($map, $target, $origin, (int) $jumps)
                ."\nIt pings when a system is added to the map (or comes back) and is in range then.",
            MapAlertType::JumpRange => sprintf(
                'Alert created for exits within %.1f ly of **%s** on **%s**%s.',
                $shipType->maxRangeLy($jdcLevel),
                $target->name,
                $map->name,
                $xlOnly ? ' (only when every hole from home is XL)' : '',
            ),
            MapAlertType::Killmail => sprintf('Alert created for kills within %d jumps of the **%s** chain.', $jumps, $map->name),
        };
    }

    private function jumpsText(int $jumps): string
    {
        return $jumps === 1 ? '1 jump' : $jumps.' jumps';
    }

    /**
     * Alerts only fire when the map changes, so say at once when the target is already in range.
     */
    private function inRangeNow(Map $map, Solarsystem $target, ?Solarsystem $origin, int $jumps): string
    {
        $origins = $origin instanceof Solarsystem
            ? [$origin->id]
            : $map->mapSolarsystems()->pluck('solarsystem_id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($origins === []) {
            return '';
        }

        $result = app(MapProximityPathfinder::class)->nearest(
            $origins,
            $target->id,
            $origin instanceof Solarsystem ? $map->wormholeEdges() : [],
            $map->mapIgnoredSolarsystems()->pluck('solarsystem_id')->map(fn (mixed $id): int => (int) $id)->all(),
            $jumps,
        );
        if (! $result instanceof ProximityResult) {
            return '';
        }

        if ($result->jumps === 0) {
            return sprintf("\n**%s is already on the map right now.**", $target->name);
        }

        $from = Solarsystem::query()->whereKey($result->matchedOriginSolarsystemId)->value('name');

        return sprintf("\n**Already in range right now:** %s from %s.", $this->jumpsText($result->jumps), $from ?? 'the chain');
    }
}
