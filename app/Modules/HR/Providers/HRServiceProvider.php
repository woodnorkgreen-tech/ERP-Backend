<?php

namespace App\Modules\HR\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Modules\HR\Console\Commands\SyncLeaveStatuses;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Observers\LeaveRequestObserver;

class HRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

            // Register Eloquent observer for real-time status sync
        LeaveRequest::observe(LeaveRequestObserver::class);

        // Register Artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncLeaveStatuses::class,
            ]);
        }

        $this->app->booted(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(__DIR__ . '/../Routes/api.php');

            // Register Leave Status Sync to run daily at midnight
            $this->app->make(Schedule::class)
                ->command('hr:sync-leave-statuses')
                ->dailyAt('00:01')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/leave-sync.log'));
        });
    }
}
