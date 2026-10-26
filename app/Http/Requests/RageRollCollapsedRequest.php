<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 57: "the static collapsed" from a pilot's mapper: when (the moment their jump arrived,
 * or now) and which character jumped.
 */
final class RageRollCollapsedRequest extends FormRequest
{
    public function authorize(#[CurrentUser] User $user): bool
    {
        $map = $this->route('map');

        return $map instanceof Map && $user->can('update', $map);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'at' => ['nullable', 'date'],
            'character_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'at.date' => 'The collapse time could not be read.',
        ];
    }
}
