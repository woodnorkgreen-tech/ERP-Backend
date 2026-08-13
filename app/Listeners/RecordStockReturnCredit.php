<?php

namespace App\Listeners;

use App\Events\Stores\StockReturned;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordStockReturnCredit implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function handle(StockReturned $event): void
    {
        app(StoresCostProducer::class)->postStockReturn($event->inventoryLog);
    }

    public function failed(StockReturned $event, Throwable $exception): void
    {
        Log::error('Stores return failed to credit the project cost account', [
            'inventory_log_id' => $event->inventoryLog->id,
            'original_issue_log_id' => $event->inventoryLog->original_issue_log_id,
            'project_id' => $event->inventoryLog->project_id,
            'error' => $exception->getMessage(),
            'requires_finance_review' => true,
        ]);
    }
}
