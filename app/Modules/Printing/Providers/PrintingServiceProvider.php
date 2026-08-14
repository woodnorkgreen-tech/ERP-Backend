<?php

namespace App\Modules\Printing\Providers;

use App\Modules\Printing\Console\Commands\DedupePrintJobs;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PrintingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DedupePrintJobs::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'middleware' => ['api', 'auth:sanctum'],
            'prefix' => 'api/printing',
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        });
    }
}
