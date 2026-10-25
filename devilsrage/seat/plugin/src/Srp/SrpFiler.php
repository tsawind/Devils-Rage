<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Srp;

use CryptaTech\Seat\SeatSrp\Helpers\SrpManager;
use CryptaTech\Seat\SeatSrp\Models\KillMail as SrpRequest;
use Seat\Eveapi\Models\Killmails\Killmail;

/**
 * Patch 54: files SRP for a loss the way the SRP plugin does when a pilot files it (same price
 * rules, same request list), on that pilot's behalf. Only loaded when the SRP plugin is installed.
 */
final class SrpFiler
{
    use SrpManager;

    /** Whether SRP has this loss already. */
    public static function filed(int $killId): bool
    {
        return SrpRequest::query()->where('kill_id', $killId)->exists();
    }

    /**
     * @return array{ok: bool, message: string, price?: float}
     */
    public function file(Killmail $killmail, int $userId): array
    {
        if (self::filed((int) $killmail->killmail_id)) {
            return ['ok' => false, 'message' => 'already filed'];
        }
        $slots = $this->srpPopulateSlots($killmail);
        $price = $slots['price'] ?? [];
        if (($price['error'] ?? 'None') !== 'None') {
            return ['ok' => false, 'message' => 'no price: '.$price['error']];
        }

        SrpRequest::create([
            'user_id' => $userId,
            'character_name' => (string) ($slots['characterName'] ?? $killmail->victim->character_id),
            'kill_id' => (int) $killmail->killmail_id,
            'kill_token' => (string) $killmail->killmail_hash,
            'approved' => 0,
            'cost' => (float) ($price['price'] ?? 0),
            'type_id' => (int) $killmail->victim->ship_type_id,
            'ship_type' => (string) ($slots['shipType'] ?? $killmail->victim->ship->typeName),
        ]);

        return ['ok' => true, 'message' => 'filed', 'price' => (float) ($price['price'] ?? 0)];
    }
}
