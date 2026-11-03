<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FleetLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetLayout>
 */
final class FleetLayoutFactory extends Factory
{
    protected $model = FleetLayout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'shared' => false,
            'layout' => ['wings' => [[
                'name' => 'Wing 1',
                'commander' => null,
                'squads' => [['name' => 'Squad 1', 'commander' => null, 'members' => []]],
            ]]],
        ];
    }

    public function shared(): self
    {
        return $this->state(['shared' => true]);
    }
}
