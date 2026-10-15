<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Jobs\ProcessStoresFinancePosting;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use Illuminate\Support\Facades\DB;

class StoresFinanceOutbox
{
    public function queue(InventoryLog $log, string $postingType): StoresFinancePosting
    {
        $posting = StoresFinancePosting::firstOrCreate(
            ['inventory_log_id' => $log->id, 'posting_type' => $postingType],
            ['status' => 'pending']
        );
        if ($posting->wasRecentlyCreated || $posting->status === 'failed') {
            // Stock has already committed when this callback runs. Preserve a
            // successful issue response if accounting fails, otherwise the user
            // may retry and issue the physical stock twice. The failed outbox row
            // remains visible to Finance with its exact error and Retry action.
            $this->processSynchronously($posting, throwOnFailure: false);
        }
        return $posting;
    }

    /**
     * Post a non-project stock reduction with the movement itself
     * (Report 76A-3): company use, damage, waste.
     *
     * Called inside the transaction that is writing the movement. Anything that
     * stops the entry — no approved account, a shut accounting period, an
     * inactive account — is thrown, so the caller's transaction rolls back and
     * the stock does not move either. The record left behind is the same one a
     * project issue leaves, already `posted`, so one list shows every Stores
     * movement's accounting and a reversal finds its entry.
     *
     * Stock carried at no value has nothing to move in the ledger; that is
     * recorded as such, not as a failure.
     *
     * @throws \InvalidArgumentException
     */
    public function postWithMovement(InventoryLog $log, string $postingType): StoresFinancePosting
    {
        $entry = app(\App\Modules\Finance\Services\StockMovementPostingService::class)
            ->postNonProjectReduction($log, $log->user_id);

        return StoresFinancePosting::updateOrCreate(
            ['inventory_log_id' => $log->id, 'posting_type' => $postingType],
            $entry
                ? ['status' => 'posted', 'journal_entry_id' => $entry->id, 'posted_at' => now(), 'attempts' => 1, 'last_error' => null]
                : ['status' => 'not_applicable', 'posted_at' => now(), 'attempts' => 1,
                    'last_error' => 'No entry required: this movement carried no stock value.'],
        );
    }

    /**
     * Post Finance in the request process, but never before the stock movement
     * commits. This deliberately removes the infrastructure dependency on a
     * long-running queue worker on shared hosting while retaining the outbox row
     * as idempotency and audit evidence.
     */
    public function processSynchronously(StoresFinancePosting $posting, bool $throwOnFailure = true): void
    {
        $process = function () use ($posting, $throwOnFailure): void {
            try {
                ProcessStoresFinancePosting::dispatchSync($posting->id);
            } catch (\Throwable $exception) {
                report($exception);
                if ($throwOnFailure) {
                    throw $exception;
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($process);
            return;
        }

        $process();
    }
}
