<?php

namespace App\Modules\ProcurementStores\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProcurementStoresServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register module config
        $configPath = __DIR__.'/../Config/procurement-stores.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'procurement-stores');
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
            'prefix' => 'api/procurement-stores',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        });
    }
}
