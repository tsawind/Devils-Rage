<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RageRollNewStaticRequest extends FormRequest
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
            'old_signature_id' => ['required', 'integer', 'exists:signatures,id'],
            'signature_id' => ['required', 'integer', 'exists:signatures,id', 'different:old_signature_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signature_id.different' => 'Pick a different signature for the new static.',
        ];
    }
}
