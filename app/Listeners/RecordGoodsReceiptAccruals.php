<?php

namespace App\Listeners;

use App\Events\GoodsReceiptRecorded;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use Illuminate\Contracts\Queue\ShouldQueue;

class RecordGoodsReceiptAccruals implements ShouldQueue
{
    public function handle(GoodsReceiptRecorded $event): void
    {
        app(ProcurementCostProducer::class)->postGoodsReceipt($event->goodsReceiptNoteId);
    }
}
