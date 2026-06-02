<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\EnquiryCreated;
use App\Listeners\SendEnquiryNotification;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        EnquiryCreated::class => [
            SendEnquiryNotification::class,
        ],
        \App\Events\QuoteApproved::class => [
            \App\Listeners\EvaluateFinancialRequirements::class,
        ],
        \App\Events\FinanceReleased::class => [
            \App\Listeners\ActivateProjectAfterFinance::class,
        ],

        // ── Board Material Workflow ───────────────────────────────────────────
        \App\Events\Stores\BoardRequestRaised::class => [
            \App\Listeners\Stores\NotifyStorekeepersOfPendingRequest::class,
        ],
        \App\Events\Stores\BoardRequestFulfilled::class => [
            \App\Listeners\Stores\NotifyLogisticsToDispatch::class,
        ],
        \App\Events\Stores\BoardsDispatchedToStation::class => [
            \App\Listeners\Stores\NotifyOperatorBoardsArrived::class,
        ],
        \App\Events\Stores\OffcutRegistered::class => [
            \App\Listeners\Stores\NotifyStorekeeperReturnOffcut::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        \App\Modules\Projects\Models\EnquiryTask::observe(\App\Observers\EnquiryTaskObserver::class);
        \App\Models\TaskMaterialsData::observe(\App\Observers\TaskMaterialsDataObserver::class);
        \App\Models\TaskBudgetData::observe(\App\Observers\TaskBudgetDataObserver::class);
        \App\Models\TaskQuoteData::observe(\App\Observers\TaskQuoteDataObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}