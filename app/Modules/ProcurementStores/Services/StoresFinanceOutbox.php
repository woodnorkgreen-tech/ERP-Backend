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
