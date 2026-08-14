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

        // Registered explicitly: the policy lives in the module rather than in
        // app/Policies, so Laravel's naming convention will not find it.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Modules\Finance\CostCollector\Models\CostLine::class,
            \App\Modules\Finance\CostCollector\Policies\CostLinePolicy::class,
        );

        // Bound to the disbursement so bulk endpoints can authorize against the
        // class and single-record endpoints against the model, under one rule.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Modules\Finance\PettyCash\Models\PettyCashDisbursement::class,
            \App\Modules\Finance\PettyCash\Policies\PettyCashPolicy::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Finance\CostCollector\Console\ProjectBudgetsCommand::class,
                \App\Modules\Finance\CostCollector\Console\BackfillPettyCashCostsCommand::class,
                \App\Modules\Finance\CostCollector\Console\AuditCostIdentityCommand::class,
                \App\Modules\Finance\CostCollector\Console\RepostMisattributedStoresCostsCommand::class,
            ]);
        }
    }
}
