<?php

namespace App\Modules\Production\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProductionServiceProvider extends ServiceProvider
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
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Register routes with proper prefix
        Route::group([
            'middleware' => ['api', 'auth:sanctum'],
            'prefix' => 'api/production',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        });
    }
}
