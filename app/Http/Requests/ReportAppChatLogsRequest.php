<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 54: the desktop app saying, for each EVE client open on the PC, whether that character
 * writes chat logs ("Log Chat to File").
 */
final class ReportAppChatLogsRequest extends FormRequest
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
        return [
            'characters' => ['present', 'array', 'max:50'],
            'characters.*.name' => ['required', 'string', 'max:64'],
            'characters.*.logging' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'characters.max' => 'At most 50 EVE windows at a time.',
        ];
    }
}
