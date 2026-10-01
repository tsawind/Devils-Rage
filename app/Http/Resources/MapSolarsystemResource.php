<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MapSolarsystem;
use App\Models\MapUserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * @mixin MapSolarsystem
 */
final class MapSolarsystemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'map_id' => $this->map_id,
            'alias' => $this->alias,
            'status' => $this->details->status,
            'occupier_alias' => $this->details->occupier_alias,
            'position' => $this->getPositionArray(),
            'pinned' => $this->pinned,
            'combat_color' => $this->combat_color,
            'combat_home' => (bool) $this->combat_home,
            'combat_active' => (bool) $this->combat_active,
            'combat_started_at' => $this->combat_started_at?->toISOString(),
            'combat_previous_color' => $this->combat_previous_color,
            // Who is working this combat home's chain (patch 12: named in the Clear chain warning).
            'combat_workers' => $this->when(
                (bool) $this->combat_home && filled($this->combat_color),
                fn (): array => MapUserSetting::query()
                    ->where('map_id', $this->map_id)
                    ->where('combat_mode', true)
                    ->where('combat_color', $this->combat_color)
                    ->with('user:id,name')
                    ->get()
                    ->map(fn (MapUserSetting $setting): ?string => $setting->user?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ),
            'scanned_at' => $this->scanned_at?->toISOString(),
            'solarsystem_id' => $this->solarsystem_id,
            'signatures_count' => $this->signatures_count,
            'uncategorized_signatures_count' => $this->uncategorized_signatures_count,
            'wormhole_signatures_count' => $this->wormhole_signatures_count,
            'map_connections_count' => $this->map_connections_count,
            'threat_level' => $this->whenLoaded('wormholeSystem', fn () => $this->wormholeSystem?->threat_level),
            // Unjumped wormhole signatures: placeholder systems on the map (patch 12).
            'pending_holes' => $this->resource->pendingHolesPayload(),
            'signatures' => $this->whenLoaded('signatures', fn () => $this->signatures->toResourceCollection(MapSignatureResource::class)),
        ];
    }

    /**
     * @return array{x: int, y: int}
     */
    private function getPositionArray(): array
    {
        return [
            'x' => $this->position_x,
            'y' => $this->position_y,
        ];
    }
}
