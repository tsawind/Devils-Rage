<?php

declare(strict_types=1);

namespace App\Events\Signatures;

use App\Events\Concerns\BroadcastsToMap;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class SignaturesChangedEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use BroadcastsToMap, Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $signature_counts
     */
    public function __construct(
        public readonly int $map_id,
        public readonly int $map_solarsystem_id,
        public readonly array $signature_counts,
    ) {}

    /**
     * @return array{map_solarsystem_id: int, signature_counts: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'map_solarsystem_id' => $this->map_solarsystem_id,
            'signature_counts' => $this->signature_counts,
        ];
    }
}
