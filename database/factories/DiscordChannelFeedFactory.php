<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscordFeedKind;
use App\Models\DiscordChannelFeed;
use App\Models\Map;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordChannelFeed>
 */
final class DiscordChannelFeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => DiscordFeedKind::ChainExits,
            'map_id' => Map::factory(),
            'discord_guild_id' => (string) $this->faker->numberBetween(100000000000000000, 999999999999999999),
            'discord_channel_id' => (string) $this->faker->numberBetween(100000000000000000, 999999999999999999),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function fuel(): self
    {
        return $this->state(['kind' => DiscordFeedKind::FuelDaily, 'map_id' => null]);
    }
}
