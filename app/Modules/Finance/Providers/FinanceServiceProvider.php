<?php

namespace App\Modules\Finance\Providers;

use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Every module reports costs through the contract, never the concrete
        // service, so Stores / HR / Procurement take no dependency on how a cost
        // is actually recorded.
        $this->app->bind(
            \App\Modules\Finance\CostCollector\Contracts\CollectsCost::class,
            \App\Modules\Finance\CostCollector\Services\CostCollectorService::class,
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations from the module
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
