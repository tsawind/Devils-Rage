<?php

declare(strict_types=1);

namespace App\Events\Maps;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Patch 12: someone cleared the combat chain you were working (or the last
 * chain on the map), so your Combat button was turned off. Sent to you alone,
 * shown as a notice that stays until you close it.
 */
final class CombatModeTurnedOffEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $user_id,
        public int $map_id,
        public string $message,
        public string $detail,
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
