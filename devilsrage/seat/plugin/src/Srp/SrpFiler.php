<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Srp;

use CryptaTech\Seat\SeatSrp\Helpers\SrpManager;
use CryptaTech\Seat\SeatSrp\Models\KillMail as SrpRequest;
use Illuminate\Support\Facades\Log;
use Seat\Eveapi\Models\Killmails\Killmail;
use Throwable;

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

    /** Whether SRP can price at all: advanced mode has its own rules, simple mode needs a price provider picked. */
    public static function hasPriceSource(): bool
    {
        if (setting('cryptatech_seat_srp_advanced_srp', true) == '1') {
            return true;
        }

        return is_numeric(setting('cryptatech_seat_srp_simple_source', true));
    }

    /**
     * @return array{ok: bool, message: string, price?: float}
     */
    public function file(Killmail $killmail, int $userId): array
    {
        if (self::filed((int) $killmail->killmail_id)) {
            return ['ok' => false, 'message' => 'already filed'];
        }
        if (! self::hasPriceSource()) {
            return ['ok' => false, 'message' => 'SRP has no price source yet: an admin sets one under SRP > Settings'];
        }
        try {
            $slots = $this->srpPopulateSlots($killmail);
        } catch (Throwable $e) {
            Log::warning('devilsrage: SRP could not price kill '.$killmail->killmail_id.': '.$e->getMessage());

            return ['ok' => false, 'message' => 'could not price it: '.mb_strimwidth($e->getMessage(), 0, 120, '…')];
        }
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
