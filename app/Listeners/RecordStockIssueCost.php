<?php

namespace App\Listeners;

use App\Events\Stores\StockIssued;
use App\Modules\Finance\CostCollector\Services\StoresCostProducer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordStockIssueCost implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 30;

    public function handle(StockIssued $event): void
    {
        app(StoresCostProducer::class)->postStockIssue($event->inventoryLog);
    }

    public function failed(StockIssued $event, Throwable $exception): void
    {
        Log::error('Stores issue failed to reach the project cost account', [
            'inventory_log_id' => $event->inventoryLog->id,
            'material_id' => $event->inventoryLog->material_id,
            'project_id' => $event->inventoryLog->project_id,
            'reference_no' => $event->inventoryLog->reference_no,
            'error' => $exception->getMessage(),
            'requires_finance_review' => true,
        ]);
    }
}
