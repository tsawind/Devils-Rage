<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Seat\SeatMains;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 39: a guest's "What should people call you?". Unique (any case) across SeAT
 * people's names and other guests.
 */
final class UpdateGuestNameRequest extends FormRequest
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
        $user = $this->user();
        $userId = $user instanceof User ? $user->id : null;

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:24',
                'regex:/^[\pL\pN][\pL\pN \'._-]*$/u',
                function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                    if (is_string($value) && SeatMains::nameTaken($value, $userId)) {
                        $fail('Someone already goes by that name. Pick another.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Type the name people should call you.',
            'name.max' => 'Keep it to 24 characters.',
            'name.regex' => 'Letters, numbers, spaces and . _ - \' only.',
        ];
    }
}
