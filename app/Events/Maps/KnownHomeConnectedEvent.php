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
 * Patch 43: a system marked as someone's home was connected to the chain: everyone on the
 * map gets a notice (and a sound) until they close it.
 */
final class KnownHomeConnectedEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $map_id,
        public int $map_solarsystem_id,
        public int $solarsystem_id,
        public string $home_of,
        public string $system_name,
        public ?string $alias,
        public ?string $way,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('Map.%d', $this->map_id)),
        ];
    }
}
