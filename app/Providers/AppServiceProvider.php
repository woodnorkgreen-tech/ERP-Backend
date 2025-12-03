<?php

namespace App\Providers;

use App\Models\ProjectEnquiry;
use App\Observers\EnquiryObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
        Schema::defaultStringLength(191);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Register model observers
        // ProjectEnquiry::observe(EnquiryObserver::class); // Observer not implemented yet

        // Route model binding
        Route::bind('enquiry', function ($value) {
            return \App\Models\ProjectEnquiry::findOrFail($value);
        });
    }
}
