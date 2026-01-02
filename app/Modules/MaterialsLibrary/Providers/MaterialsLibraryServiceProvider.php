<?php

namespace App\Modules\MaterialsLibrary\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class MaterialsLibraryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register module config
        $this->mergeConfigFrom(
            __DIR__.'/../Config/materials-library.php', 
            'materials-library'
        );
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

        // Publish config (optional)
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../Config/materials-library.php' => config_path('materials-library.php'),
            ], 'materials-library-config');
        }
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'middleware' => ['api', 'auth:sanctum'],
            'prefix' => 'api/materials-library',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        });
    }
}
