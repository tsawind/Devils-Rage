<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MapConnectionJump;
use App\Models\User;
use App\Support\JumpOptions;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateMapConnectionJumpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(#[RouteParameter('map_connection_jump')] MapConnectionJump $jump, #[CurrentUser] User $user): bool
    {
        return $user->can('update', $jump);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::in(['outbound', 'inbound', 'unknown'])],
            'ship_type_id' => ['nullable', 'sometimes', 'integer', 'exists:types,id'],
            'mass' => ['sometimes', 'integer', 'min:-100000000000', 'max:100000000000'],
            // Patch 37: the jump log's HOT / SEMI / COLD / 🧵 buttons.
            'kind' => ['sometimes', Rule::in(JumpOptions::KINDS)],
            // Patch 39: hand-added rows: who, fit and direction.
            'side' => ['sometimes', 'nullable', Rule::in(['hostile', 'friendly'])],
            'fit' => ['sometimes', 'nullable', 'array'],
            'fit.higgs' => ['boolean'],
            'fit.prop' => ['boolean'],
            'fit.plate' => ['boolean'],
        ];
    }
}
