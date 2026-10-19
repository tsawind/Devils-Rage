<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Patch 46: the desktop app reporting where one of your characters is (from EVE's chat log).
 */
final class ReportAppLocationRequest extends FormRequest
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

        return [
            'character_id' => ['required', 'integer', Rule::exists('characters', 'id')->where('user_id', $user->id)],
            'solarsystem' => ['required', 'string', 'max:64', Rule::exists('solarsystems', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'character_id.exists' => 'That character isn\'t yours.',
            'solarsystem.exists' => 'No system has that name.',
        ];
    }
}
