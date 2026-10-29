<?php

declare(strict_types=1);

namespace App\Events\Cameras;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Patch 62m: someone is watching (or wants to watch) one of your cameras: if it's on standby,
 * your share window starts streaming it.
 */
final class CameraWantedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $user_id, public int $map_id, public int $camera_id) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('User.%d', $this->user_id))];
    }

    /**
     * @return array{map_id: int, camera_id: int}
     */
    public function broadcastWith(): array
    {
        return ['map_id' => $this->map_id, 'camera_id' => $this->camera_id];
    }
}
