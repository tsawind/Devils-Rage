<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateRageRollTargetsRequest extends FormRequest
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
            'targets' => ['present', 'array', 'max:20'],
            'targets.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'targets.max' => 'At most 20 target systems.',
        ];
    }
}
