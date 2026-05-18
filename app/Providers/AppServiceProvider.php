<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Production\Observers\ProjectEnquiryObserver;
use App\Modules\Production\Observers\ProjectObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Schema::defaultStringLength(191);

        // Super Admin Bypass: Grant unrestricted access to Super Administrators
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });

        // Disable foreign key checks during migrations in local environment
        if (app()->environment('local') && app()->runningInConsole()) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            } catch (\Exception $e) {
                // Silently fail if DB is not reachable during boot
            }
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Register model observers
        ProjectEnquiry::observe(ProjectEnquiryObserver::class);
        Project::observe(ProjectObserver::class);

        // Route model binding
        Route::bind('enquiry', function ($value) {
            return \App\Models\ProjectEnquiry::findOrFail($value);
        });
    }
}