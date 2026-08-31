<?php

namespace App\Listeners;

use App\Events\Stores\StockReturned;
use App\Modules\ProcurementStores\Services\StoresFinanceOutbox;

class RecordStockReturnCredit
{
    public function __construct(private StoresFinanceOutbox $outbox) {}

    public function handle(StockReturned $event): void
    {
        $this->outbox->queue($event->inventoryLog, 'return_credit');
    }
}
