<?php

declare(strict_types=1);

namespace DevilsRage\Seat;

use DevilsRage\Seat\Commands\ImportLibraryFits;
use DevilsRage\Seat\Seeders\ScheduleSeeder;
use Seat\Services\AbstractSeatPlugin;

class DevilsRageServiceProvider extends AbstractSeatPlugin
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations/');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportLibraryFits::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/devilsrage.php', 'devilsrage');
        $this->registerDatabaseSeeders([ScheduleSeeder::class]);
    }

    public function getName(): string
    {
        return "The Devil's Rage";
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/tsawind/Devils-Rage';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-devilsrage';
    }

    public function getPackagistVendorName(): string
    {
        return 'devilsrage';
    }
}
