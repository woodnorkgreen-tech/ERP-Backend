<?php

namespace App\Modules\ProcurementStores\Providers;

use App\Modules\ProcurementStores\Console\ValueUnpricedBoardsCommand;
use App\Modules\ProcurementStores\Console\ProcessPendingFinancePostingsCommand;
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

        // Report 79E (79D P2-1): one unit reader per request. Each new instance
        // re-read units_of_measure, 299 times in one workflow summary (2.5 s).
        // Dropped after every request so a long-lived process (and the test
        // client) never reads a stale unit list.
        $this->app->scoped(\App\Modules\ProcurementStores\Services\RequirementUnits::class);
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Foundation\Http\Events\RequestHandled::class,
            fn () => $this->app->forgetInstance(\App\Modules\ProcurementStores\Services\RequirementUnits::class),
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Requisition::observe(RequisitionObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);

        // A project line waiting on a requested material links itself the
        // moment that material becomes Active — whichever door activated it
        // (activate, an edit that completes it, bulk controls).
        // Report 79C: …and is released, with the reason, when the custodian
        // does not approve it — the requirement is kept, never dropped.
        $release = function ($material, string $status) {
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($material, $status) {
                try {
                    app(\App\Modules\ProcurementStores\Services\ProjectMaterialResolution::class)->releaseRejected($material, $status);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        };
        \App\Modules\MaterialsLibrary\Models\LibraryMaterial::updated(function ($material) use ($release) {
            if ($material->wasChanged('item_status') && $material->getOriginal('item_status') === 'Under Review'
                && in_array($material->item_status, ['Inactive', 'Discontinued', 'Blocked'], true)) {
                $release($material, $material->item_status);
            }
        });
        \App\Modules\MaterialsLibrary\Models\LibraryMaterial::deleted(function ($material) use ($release) {
            if ($material->item_status === 'Under Review') {
                $release($material, 'Deleted');
            }
        });

        \App\Modules\MaterialsLibrary\Models\LibraryMaterial::updated(function ($material) {
            if (! $material->wasChanged('item_status') || $material->item_status !== 'Active') {
                return;
            }
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($material) {
                try {
                    app(\App\Modules\ProcurementStores\Services\ProjectMaterialResolution::class)
                        ->linkAwaiting($material, (int) (auth()->id() ?? $material->updated_by ?? 0));
                } catch (\Throwable $exception) {
                    // Activation never fails on this; the desk still offers Resolve.
                    report($exception);
                }
            });
        });

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValueUnpricedBoardsCommand::class,
                ProcessPendingFinancePostingsCommand::class,
                \App\Modules\ProcurementStores\Console\OperationsReadinessCommand::class,
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
