<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DiscordAssetWatch;
use App\Models\Map;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordAssetWatch>
 */
final class DiscordAssetWatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'map_id' => Map::factory(),
            'max_jumps' => 5,
            'ships_only' => false,
        ];
    }
}
