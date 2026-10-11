<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Patch 37: when a hole leaves the map on its own.
 * - by its type: a 16 h hole at 19 h, 24 h at 27 h, 48 h at 51 h (its life + 3 h);
 *   type not known (or K162 with no far side known): 27 h;
 * - End of life: 5½ h after it was first marked (EOL is under 4 h left);
 * - Critical (EOL!): 2 h after it was first marked.
 * - Imminent ("Expired, closure imminent"): 1 h after it turned imminent (an EOL!
 *   hole turns imminent by itself when its hour runs out).
 * Whichever comes first.
 */
final class HoleExpiry
{
    public const int GRACE_HOURS = 3;

    public const int UNKNOWN_TYPE_HOURS = 27;

    public const int EOL_MINUTES = 330;

    public const int CRITICAL_MINUTES = 120;

    public const int IMMINENT_MINUTES = 60;

    /** Patch 37: an EOL! hole turns imminent this long after it was first marked critical. */
    public const int CRITICAL_TO_IMMINENT_MINUTES = 60;

    /**
     * @param  int|null  $lifetime_seconds  the hole type's life (null: not known)
     */
    public static function deadline(
        CarbonImmutable $created_at,
        ?int $lifetime_seconds,
        ?CarbonImmutable $eol_since = null,
        ?CarbonImmutable $critical_since = null,
        ?CarbonImmutable $imminent_since = null,
    ): CarbonImmutable {
        $deadline = $lifetime_seconds !== null && $lifetime_seconds > 0
            ? $created_at->addSeconds($lifetime_seconds)->addHours(self::GRACE_HOURS)
            : $created_at->addHours(self::UNKNOWN_TYPE_HOURS);

        if ($eol_since instanceof CarbonImmutable) {
            $deadline = $deadline->min($eol_since->addMinutes(self::EOL_MINUTES));
        }

        if ($critical_since instanceof CarbonImmutable) {
            $deadline = $deadline->min($critical_since->addMinutes(self::CRITICAL_MINUTES));
        }
        if ($imminent_since instanceof CarbonImmutable) {
            $deadline = $deadline->min($imminent_since->addMinutes(self::IMMINENT_MINUTES));
        }

        return $deadline;
    }

    public static function earliest(?CarbonImmutable ...$times): ?CarbonImmutable
    {
        $known = array_values(array_filter($times, fn (?CarbonImmutable $time): bool => $time instanceof CarbonImmutable));
        if ($known === []) {
            return null;
        }

        return array_reduce($known, fn (CarbonImmutable $carry, CarbonImmutable $time): CarbonImmutable => $time->lessThan($carry) ? $time : $carry, $known[0]);
    }
}
