<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LifetimeStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Patch 37: remembers when a hole was FIRST marked End of life and first marked
 * Critical (eol_since / critical_since), so the latest collapse time doesn't move
 * when someone checks or re-marks it later. Back to healthy clears both.
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

            return;
        }

        $wasDying = in_array($old, [LifetimeStatus::EndOfLife->value, LifetimeStatus::Critical->value], true);
        if (! $wasDying) {
            $model->setAttribute('eol_since', $now);
            $model->setAttribute('critical_since', null);
        }
        if ($new === LifetimeStatus::Critical->value && $old !== LifetimeStatus::Critical->value) {
            $model->setAttribute('critical_since', $now);
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
