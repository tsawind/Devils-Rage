<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MapConnection;
use App\Models\MapConnectionJump;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMapConnectionJumpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(#[CurrentUser] User $user): bool
    {
        $connection = MapConnection::query()->find($this->integer('map_connection_id'));

        return $connection instanceof MapConnection
            && $user->can('create', [MapConnectionJump::class, $connection]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'map_connection_id' => ['required', 'integer', 'exists:map_connections,id'],
            // Patch 39: "unknown" for a hand-added jump nobody saw the direction of.
            'direction' => ['required', Rule::in(['outbound', 'inbound', 'unknown'])],
            'side' => ['nullable', Rule::in(['hostile', 'friendly'])],
            'ship_class' => ['nullable', Rule::in(['frigate', 'destroyer', 'cruiser', 'battlecruiser', 'battleship', 'industrial', 'capital', 'typed'])],
            'fit' => ['nullable', 'array'],
            'fit.higgs' => ['boolean'],
            'fit.prop' => ['boolean'],
            'fit.plate' => ['boolean'],
            'ship_type_id' => ['nullable', 'integer', 'exists:types,id'],
            // Patch 37: negative takes mass off (a jump logged twice, a wrong guess).
            'mass' => ['required_without:ship_type_id', 'nullable', 'integer', 'min:-100000000000', 'max:100000000000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mass.required_without' => 'Enter a mass or pick a ship type.',
        ];
    }
}
