<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\MapBackgroundMode;
use App\Models\Map;
use App\Models\MapUserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin MapUserSetting
 */
final class MapUserSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id ?? null,
            'user_id' => $this->user_id ?? null,
            'map_id' => $this->map_id,
            'tracking_allowed' => $this->tracking_allowed,
            'is_tracking' => $this->is_tracking,
            'route_allow_lifetime_status' => $this->route_allow_lifetime_status,
            'route_allow_mass_status' => $this->route_allow_mass_status,
            'route_use_evescout' => $this->route_use_evescout,
            'route_use_wormholes' => $this->route_use_wormholes ?? true,
            'route_use_frigate_holes' => $this->route_use_frigate_holes ?? true,
            'jumping_hot' => (bool) ($this->jumping_hot ?? false),
            'route_preference' => $this->route_preference,
            'security_penalty' => $this->security_penalty,
            'killmail_filter' => $this->killmail_filter,
            'introduction_confirmed_at' => $this->introduction_confirmed_at?->toISOString(),
            'prompt_for_signature_enabled' => $this->prompt_for_signature_enabled,
            'preselect_signature_enabled' => $this->preselect_signature_enabled ?? false,
            'suggest_alias_enabled' => $this->suggest_alias_enabled,
            'copy_bookmark_enabled' => $this->copy_bookmark_enabled,
            'follow_character_enabled' => $this->follow_character_enabled,
            'clipboard_enabled' => $this->clipboard_enabled ?? true,
            'layout_breakpoints' => $this->layout_breakpoints,
            'hidden_cards' => $this->hidden_cards ?? [],
            'show_threat_level' => $this->show_threat_level,
            'show_statics_first' => $this->show_statics_first,
            'compact_signature_list' => $this->compact_signature_list,
            'is_archived' => $this->is_archived ?? false,
            'is_pinned' => $this->is_pinned ?? false,
            // Patch 37: the background shown is the pilot's choice: the map's default (set by
            // managers), none, or their own picture. Pilots who already had their own keep it.
            ...$this->backgroundFields(),
            'layout_override' => $this->layout_override,
            'combat_mode' => $this->combat_mode ?? false,
            'combat_color' => $this->combat_color ?? null,
        ];
    }

    /**
     * @return array{background_choice: string, background_image_url: string|null, background_image_mode: string, own_background_image_url: string|null, own_background_image_mode: string, map_background_image_url: string|null, map_background_image_mode: string}
     */
    private function backgroundFields(): array
    {
        $disk = Storage::disk('public');
        $own = is_string($this->background_image_path) && $this->background_image_path !== '' ? $disk->url($this->background_image_path) : null;
        $ownMode = $this->background_image_mode instanceof MapBackgroundMode ? $this->background_image_mode->value : ($this->background_image_mode ?? MapBackgroundMode::Grid->value);
        $map = $this->map_id !== null ? Map::query()->find($this->map_id, ['id', 'background_image_path', 'background_image_mode']) : null;
        $mapPath = $map?->background_image_path;
        $mapUrl = is_string($mapPath) && $mapPath !== '' ? $disk->url($mapPath) : null;
        $mapMode = $map?->background_image_mode ?? MapBackgroundMode::Viewport->value;
        $choice = in_array($this->background_choice, ['map', 'none', 'own'], true) ? $this->background_choice : ($own !== null ? 'own' : 'map');

        return [
            'background_choice' => $choice,
            'background_image_url' => match ($choice) {
                'own' => $own,
                'map' => $mapUrl,
                default => null,
            },
            'background_image_mode' => $choice === 'map' ? $mapMode : $ownMode,
            'own_background_image_url' => $own,
            'own_background_image_mode' => $ownMode,
            'map_background_image_url' => $mapUrl,
            'map_background_image_mode' => $mapMode,
        ];
    }
}
