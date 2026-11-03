<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 67: character names pasted into a fleet layout, to look up their ids.
 */
final class ResolveFleetNamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'names' => ['required', 'array', 'min:1', 'max:256'],
            'names.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['names.max' => 'Up to 256 names at a time (a whole fleet).'];
    }
}
