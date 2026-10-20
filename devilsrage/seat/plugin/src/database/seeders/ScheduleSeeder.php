<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

class ScheduleSeeder extends AbstractScheduleSeeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'devilsrage:omega:remind',
                'expression' => '7 17 * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'devilsrage:pi:hourly',
                'expression' => '23 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
            [
                'command' => 'devilsrage:fits:import',
                'expression' => '41 * * * *',
                'allow_overlap' => false,
                'allow_maintenance' => false,
                'ping_before' => null,
                'ping_after' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function getDeprecatedSchedules(): array
    {
        return [];
    }
}
