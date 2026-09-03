<?php

namespace App\Listeners;

use App\Events\Stores\StockIssued;
use App\Modules\ProcurementStores\Services\StoresFinanceOutbox;

class RecordStockIssueCost
{
    public function __construct(private StoresFinanceOutbox $outbox) {}

    public function handle(StockIssued $event): void
    {
        $this->outbox->queue($event->inventoryLog, 'issue_cost');
    }
}
