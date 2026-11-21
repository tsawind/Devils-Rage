<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Solarsystem;
use App\Models\StructureName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A waypoint goes to a solar system, or (20 Nov) to a player structure the mapper knows by name.
 */
final class WaypointDestination implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('Pick a system or a structure.');

            return;
        }
        $id = (int) $value;
        if (! Solarsystem::query()->whereKey($id)->exists() && ! StructureName::query()->whereKey($id)->exists()) {
            $fail('That isn\'t a system or a known structure.');
        }
    }
}
