<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StartRageRollRequest extends FormRequest
{
    /**
     * Patch 35: members and managers can start a rage roll, viewers can't.
     */
    public function authorize(#[CurrentUser] User $user): bool
    {
        $map = $this->route('map');

        return $map instanceof Map && $user->can('update', $map);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'solarsystem_id' => ['required', 'integer', 'exists:solarsystems,id'],
            'map_webhook_id' => ['nullable', 'integer'],
            'mention' => ['nullable', 'string', 'in:none,here,everyone'],
            'system_text' => ['nullable', 'string', 'max:100'],
            'static_text' => ['nullable', 'string', 'max:200'],
            'kspace_text' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:300'],
            'targets' => ['nullable', 'array', 'max:20'],
            'targets.*' => ['string', 'max:40'],
            'scanning' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mention.in' => 'Pick None, @here or @everyone.',
            'note.max' => 'Keep the note under 300 characters.',
            'targets.max' => 'At most 20 target systems.',
        ];
    }
}
