<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Patch 37: a manager sets the map's default background (an upload, or their own picture). */
final class StoreMapDefaultBackgroundRequest extends FormRequest
{
    public function authorize(#[RouteParameter('map')] Map $map, #[CurrentUser] User $user): bool
    {
        return $user->can('updateSettings', $map);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'background_image' => ['nullable', 'image', 'mimes:jpeg,png,gif,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'background_image.max' => 'The picture can be 8 MB at most.',
        ];
    }
}
