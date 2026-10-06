<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Map;
use App\Models\MapRallyPing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MapRallyPing>
 */
final class MapRallyPingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'map_id' => Map::factory(),
            'user_id' => null,
            'character_name' => fake()->name(),
            'kind' => 'form_up',
            'title' => '⚑ Form up at Daisy',
            'channel' => 'devils-pings',
            'mention' => '@everyone',
        ];
    }
}
