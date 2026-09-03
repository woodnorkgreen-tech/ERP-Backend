<?php

namespace App\Listeners;

use App\Events\PurchaseOrderApproved;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordPurchaseOrderCommitments implements ShouldQueue
{
    public function handle(PurchaseOrderApproved $event): void
    {
        app(ProcurementCostProducer::class)->postPurchaseOrder($event->purchaseOrderId);
    }
}
