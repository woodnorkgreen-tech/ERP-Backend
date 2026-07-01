<?php

namespace App\Modules\Notifications\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/notifications.php'), 'notifications');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'notifications');

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');
    }
}
