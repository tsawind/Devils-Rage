<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiscordChannelFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiscordChannelFeed
 */
final class DiscordChannelFeedResource extends JsonResource
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
            'kind' => $this->kind->value,
            'label' => $this->kind->label(),
            'description' => $this->kind->description(),
            'discord_channel_id' => $this->discord_channel_id,
            'creator_name' => $this->whenLoaded('creator', fn (): ?string => $this->creator->alertDisplayName()),
            'last_posted_at' => $this->last_posted_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
