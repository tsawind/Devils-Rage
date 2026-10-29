<?php

declare(strict_types=1);

namespace App\Events\Cameras;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Patch 62k: the map asked your share window to turn one client's cameras on or off, or to
 * point them at another hole ('c:<pipe>', 'h:<signature>', 's:<system>', '' for none).
 */
final class CameraControlEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $user_id, public int $map_id, public string $client, public ?bool $on, public ?string $pin) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('User.%d', $this->user_id))];
    }

    /**
     * @return array{map_id: int, client: string, on: bool|null, pin: string|null}
     */
    public function broadcastWith(): array
    {
        return ['map_id' => $this->map_id, 'client' => $this->client, 'on' => $this->on, 'pin' => $this->pin];
    }
}
