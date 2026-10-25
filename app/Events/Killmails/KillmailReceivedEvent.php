<?php

declare(strict_types=1);

namespace App\Events\Killmails;

use App\Enums\SolarsystemClass;
use App\Models\Alliance;
use App\Models\Corporation;
use App\Models\Killmail;
use App\Models\Map;
use App\Models\Solarsystem;
use App\Models\Type;
use DateTimeInterface;
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

        // Patch 54: who died and who killed them, for the map's kill marker (hover / click to zKillboard).
        $victimCorporationId = data_get($this->killmail->data, 'victim.corporation_id');
        $victimAllianceId = data_get($this->killmail->data, 'victim.alliance_id');
        $attackers = (array) data_get($this->killmail->data, 'attackers', []);
        $finalBlow = collect($attackers)->first(fn ($attacker): bool => (bool) data_get($attacker, 'final_blow'));
        $finalCorporationId = data_get($finalBlow, 'corporation_id');

        return ['killmail' => [
            'id' => $this->killmail->id,
            'time' => $this->killmail->time instanceof DateTimeInterface ? $this->killmail->time->format(DATE_ATOM) : (string) $this->killmail->time,
            'victim_corporation' => is_int($victimCorporationId) ? Corporation::query()->whereKey($victimCorporationId)->value('name') : null,
            'victim_alliance' => is_int($victimAllianceId) ? Alliance::query()->whereKey($victimAllianceId)->value('name') : null,
            'attackers' => count($attackers),
            'final_blow_corporation' => is_int($finalCorporationId) ? Corporation::query()->whereKey($finalCorporationId)->value('name') : null,
            'solarsystem_id' => $this->killmail->solarsystem_id,
            'ship_name' => $ship?->name,
            'is_capital' => $ship instanceof Type && in_array($ship->group_id, self::CAPITAL_GROUP_IDS, true),
            'value' => $value,
            // Patch 39: high-sec kills flash on the map but make no sound.
            'highsec' => SolarsystemClass::fromSecurity((float) (Solarsystem::query()->whereKey($this->killmail->solarsystem_id)->value('security') ?? -1.0)) === SolarsystemClass::H,
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
