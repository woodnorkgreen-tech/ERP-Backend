<?php

namespace App\Modules\ProcurementStores\Jobs;

use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessStoresFinancePosting implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $postingId)
    {
        $this->onQueue('stores-finance');
    }

    public function handle(StoresCostProducer $producer): void
    {
        $posting = StoresFinancePosting::with('inventoryLog')->findOrFail($this->postingId);
        if ($posting->status === 'posted' && $posting->cost_line_id) return;

        $posting->update([
            'status' => 'processing', 'attempts' => $posting->attempts + 1,
            'processing_started_at' => now(), 'last_error' => null, 'next_retry_at' => null,
        ]);

        try {
            // A historical zero-value issue has no project cost to reverse. It
            // is a valid no-posting outcome, not an accounting failure. Keep the
            // outbox record as terminal audit evidence without inventing a
            // zero-value cost line.
            if ($posting->posting_type === 'return_credit') {
                $return = $posting->inventoryLog;
                $issue = $return?->original_issue_log_id
                    ? InventoryLog::with('material')->find($return->original_issue_log_id)
                    : null;
                $originalCostExists = $issue && CostLine::where('source_type', InventoryLog::class)
                    ->where('source_id', $issue->id)->where('source_ref', 'stock-issue')
                    ->where('status', CostLine::STATUS_VERIFIED)->exists();
                $unitCost = (float) ($issue?->receipt_unit_cost ?? $issue?->material?->unit_cost ?? 0);
                if ($issue && !$originalCostExists && $unitCost <= 0) {
                    $posting->update([
                        'status' => 'not_applicable', 'posted_at' => now(),
                        'processing_started_at' => null,
                        'last_error' => 'No return credit required: the original issue had zero recorded value.',
                    ]);
                    return;
                }
            }

            $line = $posting->posting_type === 'return_credit'
                ? $producer->postStockReturn($posting->inventoryLog)
                : $producer->postStockIssue($posting->inventoryLog);
            if (!$line) throw new RuntimeException('Finance did not create a cost line for this movement. Check its project, quantity and unit cost.');
            $posting->update([
                'status' => 'posted', 'cost_line_id' => $line->id, 'posted_at' => now(),
                'processing_started_at' => null, 'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $posting->update([
                'status' => 'pending', 'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'next_retry_at' => now()->addSeconds($this->backoff[min(max($posting->attempts - 1, 0), count($this->backoff) - 1)]),
                'processing_started_at' => null,
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        StoresFinancePosting::whereKey($this->postingId)->update([
            'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            'processing_started_at' => null, 'next_retry_at' => null,
        ]);
    }
}
