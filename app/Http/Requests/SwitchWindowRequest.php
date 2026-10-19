<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Patch 45d: switch another window of yours from one of your characters to another.
 */
final class SwitchWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $mine = Rule::exists('characters', 'id')->where('user_id', $user->id);

        return [
            'from_character_id' => ['required', 'integer', $mine],
            'to_character_id' => ['required', 'integer', 'different:from_character_id', $mine],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_character_id.exists' => 'That character isn\'t yours.',
            'to_character_id.exists' => 'That character isn\'t yours.',
            'to_character_id.different' => 'Pick another character.',
        ];
    }
}
