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
 * Patch 13: a notice for one person that stays until they close it, e.g.
 * "Aidan took 1, you are now 2". With `copy`, clicking the notice copies
 * that text (the new bookmark name).
 */
final class MapNoticeEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $user_id,
        public int $map_id,
        public string $message,
        public string $detail,
        public ?int $signature_id = null,
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
