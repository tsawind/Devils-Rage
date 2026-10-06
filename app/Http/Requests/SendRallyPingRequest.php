<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class SendRallyPingRequest extends FormRequest
{
    /**
     * Patch 29: members and managers can ping, viewers can't.
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
            'map_webhook_id' => ['required', 'integer'],
            'mention' => ['required', 'string', 'regex:/^(none|here|everyone|role:\d+)$/'],
            'sections' => ['nullable', 'array', 'max:8'],
            'sections.*.title' => ['required', 'string', 'max:60'],
            'sections.*.text' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'map_webhook_id.required' => 'Pick a channel to ping.',
            'mention.regex' => 'Pick who to mention.',
            'note.max' => 'Keep the note under 300 characters.',
            'sections.max' => 'Too many sections for one ping.',
            'sections.*.text.max' => 'A section is too long for Discord.',
        ];
    }
}
