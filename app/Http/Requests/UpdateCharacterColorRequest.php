<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Character;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 41: a character's colour, set by its owner.
 */
final class UpdateCharacterColorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $character = $this->route('character');
        $user = $this->user();

        return $character instanceof Character && $user instanceof User && $character->user_id === $user->id;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => 'Pick a colour like #22c55e.',
        ];
    }
}
