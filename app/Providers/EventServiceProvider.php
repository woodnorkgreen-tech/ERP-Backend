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

        // Completing the budget task opens the project's cost account. Queued,
        // so a cost-ledger problem can never stop someone completing a task.
        \App\Events\EnquiryTaskCompleted::class => [
            \App\Listeners\ProjectBudgetLinesOnTaskCompletion::class,
        ],

        // The budget's material list is a mirror of the materials task's, so
        // every save refreshes it. This replaced a Sync button and an orange
        // "materials have changed" banner — a budget was only ever as current as
        // somebody noticing, and two copies with a manual reconciler is how they
        // drifted.
        \App\Events\MaterialsListChanged::class => [
            \App\Listeners\SyncBudgetWithMaterialsList::class,
        ],

        // A budget write moves the ceiling every "budget vs actual" figure is
        // measured against, so the planned lines behind that ceiling are
        // re-projected from it. Queued, for the same reason as the rest.
        \App\Events\BudgetLinesChanged::class => [
            \App\Listeners\ProjectBudgetLines::class,
        ],

        // An approved addition is a budget revision, so it has to move the
        // ceiling every "budget vs actual" figure is measured against. Before
        // this, approval changed a status and nothing else, and the project went
        // on being judged against its pre-addition budget.
        \App\Events\BudgetAdditionApproved::class => [
            \App\Listeners\ProjectApprovedBudgetAddition::class,
        ],

        // Petty cash actuals into the project's cost account. Queued, so the
        // cost ledger can never stop somebody paying out of the tin — and the
        // void listener keeps a backed-out payment from overstating a project.
        \App\Events\PettyCashDisbursementPaid::class => [
            \App\Listeners\RecordPettyCashCost::class,
        ],
        \App\Events\PettyCashDisbursementVoided::class => [
            \App\Listeners\ReversePettyCashCost::class,
        ],
        \App\Events\PurchaseOrderApproved::class => [
            \App\Listeners\RecordPurchaseOrderCommitments::class,
        ],
        \App\Events\GoodsReceiptRecorded::class => [
            \App\Listeners\RecordGoodsReceiptAccruals::class,
        ],
        \App\Events\Stores\StockIssued::class => [
            \App\Listeners\RecordStockIssueCost::class,
        ],
        \App\Events\Stores\StockReturned::class => [
            \App\Listeners\RecordStockReturnCredit::class,
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
