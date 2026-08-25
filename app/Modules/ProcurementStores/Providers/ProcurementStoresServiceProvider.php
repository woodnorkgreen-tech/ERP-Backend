<?php

namespace App\Modules\ProcurementStores\Providers;

use App\Modules\ProcurementStores\Console\ValueUnpricedBoardsCommand;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Observers\PurchaseOrderObserver;
use App\Modules\ProcurementStores\Observers\RequisitionObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProcurementStoresServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/procurement-stores.php', 'procurement-stores');
        $this->mergeConfigFrom(__DIR__.'/../Config/boards.php', 'boards');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Requisition::observe(RequisitionObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValueUnpricedBoardsCommand::class,
            ]);
        }

        // Register routes
        $this->registerRoutes();
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'middleware' => ['api', 'auth:sanctum', 'active'],
            'prefix' => 'api/procurement-stores',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        });
    }
}
