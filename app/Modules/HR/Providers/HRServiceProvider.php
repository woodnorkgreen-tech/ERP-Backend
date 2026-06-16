<?php

namespace App\Modules\HR\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Modules\HR\Console\Commands\SyncLeaveStatuses;
use App\Modules\HR\Console\Commands\SyncHikvisionAttendance;
use App\Modules\HR\Console\Commands\NotifyExpiringContracts;
use App\Modules\HR\Console\Commands\ReprocessAttendance;
use App\Modules\HR\Console\Commands\ReconcileAttendance;
use App\Modules\HR\Console\Commands\SyncKenyaHolidays;
use App\Modules\HR\Console\Commands\SyncAttendanceOvertime;
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

        // Register Eloquent observers
        LeaveRequest::observe(LeaveRequestObserver::class);
        \App\Modules\HR\Models\Employee::observe(\App\Modules\HR\Observers\EmployeeObserver::class);

        // Register Artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncLeaveStatuses::class,
                SyncHikvisionAttendance::class,
                NotifyExpiringContracts::class,
                ReprocessAttendance::class,
                ReconcileAttendance::class,
                SyncKenyaHolidays::class,
                SyncAttendanceOvertime::class,
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

            $this->app->make(Schedule::class)
                ->command('hr:notify-expiring-contracts')
                ->dailyAt('07:00')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/contract-expiry.log'));

            $schedule = $this->app->make(Schedule::class);
            foreach (['09:00', '18:00', '23:00'] as $time) {
                $schedule->command('attendance:sync-hikvision')
                    ->dailyAt($time)
                    ->withoutOverlapping()
                    ->onOneServer()
                    ->appendOutputTo(storage_path('logs/hikvision-sync.log'));
            }

            $schedule->command('attendance:reconcile')
                ->dailyAt('23:30')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/attendance-reconciliation.log'));

            $schedule->command('attendance:sync-kenya-holidays')
                ->monthlyOn(1, '02:30')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/attendance-holidays.log'));
        });
    }
}
