<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiscordAssetWatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiscordAssetWatch
 */
final class DiscordAssetWatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'creator_name' => $this->whenLoaded('user', fn (): string => $this->user->alertDisplayName()),
            'max_jumps' => $this->max_jumps,
            'ships_only' => $this->ships_only,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
