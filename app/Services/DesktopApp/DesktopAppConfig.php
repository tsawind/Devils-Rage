<?php

declare(strict_types=1);

namespace App\Services\DesktopApp;

use App\Models\AppSetting;

/**
 * Patch 46: what every copy of the desktop app reads from the mapper (desktop-app/config) at start
 * and every half hour. Nothing in it is secret: it is served without a login.
 *
 * - motd: shown on the app's start screen
 * - min_version: older apps are told to update
 * - features: switches for app features; off turns the feature off for everyone
 */
final class DesktopAppConfig
{
    /** The app features directors can switch off, with their label on the admin page. */
    public const array FEATURES = [
        'chat_log_location' => "Instant location from EVE's chat logs",
        'scanner_pop_out' => 'Scanner pop-out as an app window',
    ];

    /**
     * @return array{motd: string|null, min_version: string|null, features: array<string, bool>, updated_by: string|null, updated_at: string|null}
     */
    public function current(): array
    {
        $settings = AppSetting::query()->whereIn('key', ['motd', 'min_version', 'features'])->get()->keyBy('key');

        $stored = $settings->get('features')?->value;
        $features = [];
        foreach (array_keys(self::FEATURES) as $feature) {
            $features[$feature] = ! is_array($stored) || ($stored[$feature] ?? true) !== false;
        }

        $latest = $settings->sortByDesc('updated_at')->first();
        $motd = $settings->get('motd')?->value;
        $minVersion = $settings->get('min_version')?->value;

        return [
            'motd' => is_string($motd) && $motd !== '' ? $motd : null,
            'min_version' => is_string($minVersion) && $minVersion !== '' ? $minVersion : null,
            'features' => $features,
            'updated_by' => $latest?->updated_by_name,
            'updated_at' => $latest?->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, bool>  $features
     */
    public function update(?string $motd, ?string $minVersion, array $features, string $by): void
    {
        $known = array_intersect_key($features, self::FEATURES);

        foreach (['motd' => $motd, 'min_version' => $minVersion, 'features' => $known] as $key => $value) {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_name' => $by]);
        }
    }
}
