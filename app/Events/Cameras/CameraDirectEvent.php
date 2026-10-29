<?php

declare(strict_types=1);

namespace App\Events\Cameras;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Patch 62q: watching your own camera on the same PC (or home network) straight from your
 * share window, not through the relay. The mapper only passes the two windows' WebRTC
 * offer / answer along your own channel; the picture never touches the server.
 */
final class CameraDirectEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  'offer'|'answer'|'bye'  $step
     */
    public function __construct(public int $user_id, public int $camera_id, public string $key, public string $step, public string $sdp) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('User.%d', $this->user_id))];
    }

    /**
     * @return array{camera_id: int, key: string, step: string, sdp: string}
     */
    public function broadcastWith(): array
    {
        return ['camera_id' => $this->camera_id, 'key' => $this->key, 'step' => $this->step, 'sdp' => $this->sdp];
    }
}
