<?php

declare(strict_types=1);

namespace App\Events\Cameras;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Patch 62k: your share window's clients (on / off, what they watch) changed; your maps
 * reload them for the pilot tabs and the watcher.
 */
final class CameraClientsChangedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $user_id, public int $map_id) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('User.%d', $this->user_id))];
    }

    /**
     * @return array{map_id: int}
     */
    public function broadcastWith(): array
    {
        return ['map_id' => $this->map_id];
    }
}
