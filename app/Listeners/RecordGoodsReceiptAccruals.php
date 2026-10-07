<?php

namespace App\Listeners;

use App\Events\GoodsReceiptRecorded;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Services\FinanceEventPoster;

/**
 * Accepted goods become an accrual: Dr Inventory / Cr Accrued Expenses, and
 * the order line's commitment is released for what arrived.
 *
 * Not queued (Report 76A). This is the only thing that debits Inventory for a
 * purchase, so a receipt whose accrual silently never ran left every later
 * Stores issue crediting stock the books had never received. It runs in the
 * receiving request, after the receipt commits, through FinanceEventPoster.
 * Raised again when an inspection decision releases a held line;
 * `postGoodsReceipt()` skips lines it has already accrued.
 */
class RecordGoodsReceiptAccruals
{
    public function __construct(private FinanceEventPoster $postings) {}

    public function handle(GoodsReceiptRecorded $event): void
    {
        $this->postings->record(FinanceEventPoster::GRN_ACCRUAL, $event->goodsReceiptNoteId);
    }

    public function post(array $payload, int $goodsReceiptNoteId): string
    {
        $lines = app(ProcurementCostProducer::class)->postGoodsReceipt($goodsReceiptNoteId);

        return "accrued {$lines} receipt line(s)";
    }
}
