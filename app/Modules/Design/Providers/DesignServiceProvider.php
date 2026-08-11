<?php

namespace App\Modules\Design\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use App\Modules\Design\Console\Commands\SyncUpcomingDesignJobs;

class DesignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncUpcomingDesignJobs::class,
            ]);
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('design:sync-upcoming')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/design-sync.log'));
        });
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'middleware' => ['api', 'auth:sanctum'],
            'prefix' => 'api/design',
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        });
    }
}
