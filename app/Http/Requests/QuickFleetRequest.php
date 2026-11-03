<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Fleets\QuickFleet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Patch 72: your characters to put in the fleet now, each with the squad (role) it goes in.
 */
final class QuickFleetRequest extends FormRequest
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
            'members' => ['required', 'array', 'min:1', 'max:256'],
            'members.*.id' => ['required', 'integer', 'min:1'],
            'members.*.name' => ['required', 'string', 'max:64'],
            'members.*.role' => ['required', 'string', Rule::in(array_keys(QuickFleet::SQUADS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['members.required' => 'Tick at least one character.', 'members.*.role.in' => 'Pick a squad for every character.'];
    }
}
