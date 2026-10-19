<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 46: a director changing the desktop app's settings for everyone.
 */
final class UpdateAppConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAppAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motd' => ['nullable', 'string', 'max:500'],
            'min_version' => ['nullable', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'features' => ['array'],
            'features.*' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motd.max' => 'Keep the message under 500 characters.',
            'min_version.regex' => 'Use a version like 0.1.4.',
        ];
    }
}
