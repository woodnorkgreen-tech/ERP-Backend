<?php

namespace App\Modules\ArchivalTask\Providers;

use Illuminate\Support\ServiceProvider;

class ArchivalTaskServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations from module directory
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
