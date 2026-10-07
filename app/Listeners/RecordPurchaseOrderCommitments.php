<?php

namespace App\Listeners;

use App\Events\PurchaseOrderApproved;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Services\FinanceEventPoster;

/**
 * An approved purchase order commits its project's (or department's) money.
 *
 * Not queued (Report 76A). The commitment is posted in the approving request,
 * once the approval has committed, through FinanceEventPoster — which records
 * the outcome and turns a failure into a visible, retryable row instead of
 * stopping the approval. `postPurchaseOrder()` is idempotent per order line.
 */
class RecordPurchaseOrderCommitments
{
    public function __construct(private FinanceEventPoster $postings) {}

    public function handle(PurchaseOrderApproved $event): void
    {
        $this->postings->record(FinanceEventPoster::PO_COMMITMENT, $event->purchaseOrderId);
    }

    public function post(array $payload, int $purchaseOrderId): string
    {
        $lines = app(ProcurementCostProducer::class)->postPurchaseOrder($purchaseOrderId);

        return $lines > 0 ? "committed {$lines} order line(s)" : 'nothing to commit (order not approved or has no lines)';
    }
}
