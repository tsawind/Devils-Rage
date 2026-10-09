<?php

declare(strict_types=1);

namespace App\Events\Killmails;

use App\Models\Killmail;
use App\Models\Map;
use App\Models\Type;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class KillmailReceivedEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    /** Patch 37: capitals get the heavier kill sound (dreadnoughts, carriers, FAX, supers, titans, Rorquals, lancers). */
    private const array CAPITAL_GROUP_IDS = [30, 485, 547, 659, 883, 1538, 4594];

    public function __construct(private readonly Map $map, private readonly ?Killmail $killmail = null) {}

    /**
     * Patch 37: the map flashes the kill's system, so the browser needs to know where it
     * was (the old event carried nothing, so the flash never fired).
     *
     * @return array{killmail: array{id: int, solarsystem_id: int, ship_name: string|null, is_capital: bool, value: float|null}|null}
     */
    public function broadcastWith(): array
    {
        if (! $this->killmail instanceof Killmail) {
            return ['killmail' => null];
        }

        $shipTypeId = data_get($this->killmail->data, 'victim.ship_type_id');
        $ship = is_int($shipTypeId) ? Type::query()->whereKey($shipTypeId)->first(['id', 'name', 'group_id']) : null;
        $totalValue = data_get($this->killmail->zkb, 'totalValue');
        $value = is_numeric($totalValue) ? (float) $totalValue : null;

        return ['killmail' => [
            'id' => $this->killmail->id,
            'solarsystem_id' => $this->killmail->solarsystem_id,
            'ship_name' => $ship?->name,
            'is_capital' => $ship instanceof Type && in_array($ship->group_id, self::CAPITAL_GROUP_IDS, true),
            'value' => $value,
        ]];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('Map.%d', $this->map->id)),
        ];
    }
}
