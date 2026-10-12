<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Enums\MapAlertDeliveryType;
use App\Enums\MapAlertType;
use App\Models\MapAlert;
use App\Models\Solarsystem;

final readonly class DiscordAlertFormatter
{
    /**
     * One line for /alerts list and /alerts map:
     * "`6` System near chain: J134702 within 1 jump · DM · Daisy".
     */
    public function format(MapAlert $alert, bool $showCreator = false): string
    {
        $creator = $showCreator && $alert->creator !== null ? ' · by '.$alert->creator->name : '';

        return sprintf(
            '`%d` %s%s: %s · %s · %s%s',
            $alert->id,
            $alert->is_active ? '' : '~~off~~ ('.($alert->disabled_reason?->label() ?? 'switched off').') ',
            $alert->type->label(),
            $this->rules($alert),
            $this->destination($alert, true),
            $alert->map->name,
            $creator,
        );
    }

    /**
     * Plain text for Discord's option picker (no markdown, at most 100 characters):
     * "System near chain · J134702 ≤1j · DM · Daisy · #6".
     */
    public function label(MapAlert $alert): string
    {
        $text = sprintf(
            '%s%s · %s · %s · %s · #%d',
            $alert->is_active ? '' : 'Off: '.($alert->disabled_reason?->label() ?? 'switched off').' · ',
            $alert->type->label(),
            $this->shortDescription($alert),
            $this->destination($alert, false),
            $alert->map->name,
            $alert->id,
        );

        return mb_strlen($text) > 100 ? mb_substr($text, 0, 99).'…' : $text;
    }

    private function destination(MapAlert $alert, bool $mentionChannel): string
    {
        return match ($alert->delivery_type) {
            MapAlertDeliveryType::Webhook => 'webhook',
            MapAlertDeliveryType::DiscordDm => 'DM',
            MapAlertDeliveryType::DiscordChannel => $mentionChannel && $alert->discord_channel_id !== null ? '<#'.$alert->discord_channel_id.'>' : 'channel',
        };
    }

    private function jumps(?int $jumps): string
    {
        return ($jumps ?? 0) === 1 ? '1 jump' : ($jumps ?? 0).' jumps';
    }

    /**
     * What the alert watches for, e.g. "kills inside the chain, no high-sec". Needs the
     * alert's target and origin systems loaded.
     */
    public function rules(MapAlert $alert): string
    {
        // Kill alerts have no target system.
        $target = $alert->target_solarsystem_id === null ? '?' : $alert->targetSolarsystem->name;

        return match ($alert->type) {
            MapAlertType::Killmail => ($alert->max_jumps === 0 ? 'kills inside the chain' : sprintf('kills within %s of the chain', $this->jumps($alert->max_jumps)))
                .($alert->exclude_highsec ? ', no high-sec' : ''),
            MapAlertType::JumpRange => sprintf(
                '%s range from %s%s%s',
                $alert->ship_type?->label() ?? 'capital',
                $target,
                $alert->include_highsec ? ', highsec too' : '',
                $alert->xl_only ? ', only through XL holes' : '',
            ),
            MapAlertType::Proximity => $alert->originSolarsystem instanceof Solarsystem
                ? sprintf('%s within %s of %s', $target, $this->jumps($alert->max_jumps), $alert->originSolarsystem->name)
                : sprintf('%s within %s', $target, $this->jumps($alert->max_jumps)),
        };
    }

    private function shortDescription(MapAlert $alert): string
    {
        // Kill alerts have no target system.
        $target = $alert->target_solarsystem_id === null ? '?' : $alert->targetSolarsystem->name;

        return match ($alert->type) {
            MapAlertType::Killmail => sprintf('≤%dj', $alert->max_jumps ?? 0),
            MapAlertType::JumpRange => sprintf('%s from %s%s', $alert->ship_type?->label() ?? 'capital', $target, $alert->xl_only ? ' (XL)' : ''),
            MapAlertType::Proximity => $alert->originSolarsystem instanceof Solarsystem
                ? sprintf('%s ≤%dj of %s', $target, $alert->max_jumps ?? 0, $alert->originSolarsystem->name)
                : sprintf('%s ≤%dj', $target, $alert->max_jumps ?? 0),
        };
    }
}
