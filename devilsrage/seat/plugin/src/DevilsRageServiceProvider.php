<?php

declare(strict_types=1);

namespace DevilsRage\Seat;

use DevilsRage\Seat\Commands\ImportLibraryFits;
use DevilsRage\Seat\Commands\RemindOmega;
use DevilsRage\Seat\Http\Composers\FolderedSidebar;
use DevilsRage\Seat\Http\Middleware\StartPage;
use DevilsRage\Seat\Seeders\ScheduleSeeder;
use DevilsRage\Seat\Services\Corporation;
use DevilsRage\Seat\Services\Leadership;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Seat\Services\AbstractSeatPlugin;
use Seat\Web\Models\User;

class DevilsRageServiceProvider extends AbstractSeatPlugin
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations/');
        $this->loadViewsFrom(__DIR__.'/resources/views/', 'devilsrage');

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        }

        // Menu entries "Corp layout" and "FC planner"; also checked again in the controllers.
        Gate::define('devilsrage-leader', fn (User $user): bool => $this->app->make(Leadership::class)->isLeader($user));

        // The sidebar in folders, as each pilot arranged it (after SeAT's own composer).
        $this->app->booted(function (): void {
            View::composer('web::includes.sidebar', FolderedSidebar::class);
        });
        $this->app['router']->pushMiddlewareToGroup('web', StartPage::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ImportLibraryFits::class, RemindOmega::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/devilsrage.php', 'devilsrage');
        $this->mergeConfigFrom(__DIR__.'/Config/devilsrage.sidebar.php', 'package.sidebar');
        $this->registerPermissions(__DIR__.'/Config/devilsrage.permissions.php', 'devilsrage');
        $this->registerDatabaseSeeders([ScheduleSeeder::class]);

        $this->app->scoped(Leadership::class);
        $this->app->scoped(Corporation::class);
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
