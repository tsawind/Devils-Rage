<?php

declare(strict_types=1);

namespace App\Events\Cameras;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Patch 62: a hole camera saw a wormhole activation (or a ship appear / vanish at the hole).
 * Every open map shows a purple "Activation" on the pipe with a 60 s jump-cloak countdown.
 */
final class CameraActivityEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $map_id,
        public int $event_id,
        public int $camera_id,
        public string $camera_name,
        public ?int $map_connection_id,
        public ?int $map_solarsystem_id,
        public string $kind,
        public string $happened_at,
        public string $heard_by,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('Map.%d', $this->map_id))];
    }
}
