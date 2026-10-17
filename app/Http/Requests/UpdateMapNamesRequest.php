<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MapSolarsystem;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Patch 39: a change to a system's names (loop popup, ✕ on a floating name, old chain rows).
 */
final class UpdateMapNamesRequest extends FormRequest
{
    public function authorize(#[RouteParameter('mapSolarsystem')] MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): bool
    {
        return $user->can('update', $mapSolarsystem);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['switch', 'drop', 'adopt', 'keep', 'hole', 'rename'])],
            'signature_id' => ['nullable', 'integer', 'required_if:action,switch,adopt,hole,rename'],
            'name' => ['nullable', 'string', 'max:32', 'required_if:action,hole,rename'],
            'mode' => ['nullable', Rule::in(['plain', 'swap', 'bump', 'float'])],
            'next' => ['nullable', 'string', 'max:32'],
            'undo_token' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signature_id.required_if' => 'Pick the hole the name comes from.',
            'name.required_if' => 'No new name given.',
        ];
    }
}
