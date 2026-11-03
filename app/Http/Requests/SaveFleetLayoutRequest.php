<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Patch 67: a fleet layout as the editor sends it. EVE fleets hold up to 25 wings of up to 25
 * squads; wing and squad names are at most 10 characters.
 */
final class SaveFleetLayoutRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60'],
            'shared' => ['boolean'],
            'layout' => ['required', 'array'],
            'layout.wings' => ['required', 'array', 'min:1', 'max:25'],
            'layout.wings.*.name' => ['required', 'string', 'max:10'],
            'layout.wings.*.commander' => ['nullable', 'array'],
            'layout.wings.*.commander.id' => ['required_with:layout.wings.*.commander', 'integer', 'min:1'],
            'layout.wings.*.commander.name' => ['required_with:layout.wings.*.commander', 'string', 'max:64'],
            'layout.wings.*.squads' => ['required', 'array', 'min:1', 'max:25'],
            'layout.wings.*.squads.*.name' => ['required', 'string', 'max:10'],
            'layout.wings.*.squads.*.commander' => ['nullable', 'array'],
            'layout.wings.*.squads.*.commander.id' => ['required_with:layout.wings.*.squads.*.commander', 'integer', 'min:1'],
            'layout.wings.*.squads.*.commander.name' => ['required_with:layout.wings.*.squads.*.commander', 'string', 'max:64'],
            'layout.wings.*.squads.*.members' => ['present', 'array', 'max:256'],
            'layout.wings.*.squads.*.members.*.id' => ['required', 'integer', 'min:1'],
            'layout.wings.*.squads.*.members.*.name' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'layout.wings.max' => 'A fleet holds up to 25 wings.',
            'layout.wings.*.squads.max' => 'A wing holds up to 25 squads.',
            'layout.wings.*.name.max' => 'EVE keeps wing names to 10 characters.',
            'layout.wings.*.squads.*.name.max' => 'EVE keeps squad names to 10 characters.',
        ];
    }
}
