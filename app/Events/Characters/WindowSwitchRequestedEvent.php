<?php

declare(strict_types=1);

namespace App\Events\Characters;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Patch 45d: switch the window (in any browser: Edge next to Chrome) that shows one of your
 * characters on its own to another of your characters, its pop-out too.
 */
final class WindowSwitchRequestedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $user_id,
        public int $from_character_id,
        public int $to_character_id,
        public string $to_name,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('User.%d', $this->user_id)),
        ];
    }
}
