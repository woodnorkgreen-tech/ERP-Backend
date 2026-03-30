<?php

namespace App\Modules\Logistics\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class LogisticsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register module config
        $configPath = __DIR__.'/../Config/logistics.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'logistics');
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Register routes
        $this->registerRoutes();
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
{
    Route::group([
        'middleware' => ['api', 'auth:sanctum'],
        'prefix' => 'api',  // ← remove 'logistics'
    ], function () {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    });
}
}
