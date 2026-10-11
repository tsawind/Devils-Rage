<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LifetimeStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 37: remembers when a hole was FIRST marked End of life and first marked
 * Critical (eol_since / critical_since), so the latest collapse time doesn't move
 * when someone checks or re-marks it later. Patch 37: also the first Imminent mark
 * (imminent_since). Back to healthy clears them all.
 */
final class LifeMarks
{
    public static function apply(Model $model): void
    {
        if (! $model->isDirty('lifetime')) {
            return;
        }

        $new = self::value($model->getAttributes()['lifetime'] ?? null);
        $old = $model->exists ? self::value($model->getOriginal('lifetime')) : null;
        $now = now()->toImmutable();

        if ($new === LifetimeStatus::Healthy->value || $new === null) {
            $model->setAttribute('eol_since', null);
            $model->setAttribute('critical_since', null);
            $model->setAttribute('imminent_since', null);

            return;
        }

        $dying = [LifetimeStatus::EndOfLife->value, LifetimeStatus::Critical->value, LifetimeStatus::Imminent->value];
        if (! in_array($old, $dying, true)) {
            $model->setAttribute('eol_since', $now);
            $model->setAttribute('critical_since', null);
            $model->setAttribute('imminent_since', null);
        }
        $pastCritical = [LifetimeStatus::Critical->value, LifetimeStatus::Imminent->value];
        if (in_array($new, $pastCritical, true) && ! in_array($old, $pastCritical, true)) {
            $model->setAttribute('critical_since', $now);
        }
        // Patch 37: "Expired, closure imminent" (marked, or EOL! whose hour ran out).
        if ($new === LifetimeStatus::Imminent->value && $old !== LifetimeStatus::Imminent->value) {
            $model->setAttribute('imminent_since', $now);
        }
    }

    private static function value(mixed $lifetime): ?string
    {
        if ($lifetime instanceof LifetimeStatus) {
            return $lifetime->value;
        }

        return is_string($lifetime) ? $lifetime : null;
    }
}
