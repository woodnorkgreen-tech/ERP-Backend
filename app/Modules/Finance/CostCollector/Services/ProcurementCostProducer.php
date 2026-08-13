<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;

/** Approved purchase orders reserve budget; they do not create a GL journal. */
class ProcurementCostProducer
{
    public function __construct(private CostCollectorService $collector) {}

    public function postPurchaseOrder(int $purchaseOrderId): int
    {
        $po = PurchaseOrder::with([
            'supplier', 'requisition', 'items.requisitionItem.expenseCode',
        ])->findOrFail($purchaseOrderId);

        if ($po->status !== 'approved') {
            return 0;
        }

        $posted = 0;
        foreach ($po->items as $item) {
            $requisitionItem = $item->requisitionItem;
            $enquiryId = $requisitionItem?->project_enquiry_id
                ?: ($po->requisition?->requested_by_type === 'project' ? $po->requisition?->project_id : null);
            $jobNumber = $po->requisition?->job_number;
            if (! $enquiryId && blank($jobNumber)) {
                continue; // Departmental procurement has no project cost object.
            }

            $planned = $this->plannedLine($requisitionItem);
            $description = $item->custom_description ?: $item->material?->name ?: "PO item {$item->id}";
            $amount = bcmul((string) $item->quantity, (string) $item->unit_price, 2);
            $this->collector->postFromSource(new CostContext(
                expenseCode: (string) ($requisitionItem?->expenseCode?->code ?? ''),
                amount: $amount,
                nature: CostLine::NATURE_COMMITTED,
                enquiryId: $enquiryId ? (int) $enquiryId : null,
                jobNumber: $jobNumber,
                sourceType: PurchaseOrderItem::class, sourceId: $item->id,
                sourceRef: 'commitment',
                incurredAt: (string) ($po->approved_at ?? $po->date),
                payeeType: 'SUPPLIER', payeeId: $po->supplier_id,
                payeeName: $po->supplier?->supplier_name,
                consumesLineId: $planned?->id,
                description: $description,
                details: array_filter([
                    'budget_category' => $planned?->details['budget_category'] ?? null,
                    'po_number' => $po->po_number,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'requisition_item_id' => $requisitionItem?->id,
                    'purchase_order_item_id' => $item->id,
                ], fn ($value) => $value !== null),
            ));
            $posted++;
        }
        return $posted;
    }

    /** Accepted quantities become accruals; only the unreceived balance stays committed. */
    public function postGoodsReceipt(int $goodsReceiptNoteId): int
    {
        $grn = GoodsReceiptNote::with([
            'purchaseOrder.supplier',
            'purchaseOrder.requisition',
            'items.purchaseOrderItem.requisitionItem.expenseCode',
        ])->findOrFail($goodsReceiptNoteId);
        $posted = 0;

        foreach ($grn->items->where('accepted', true) as $receiptItem) {
            $poItem = $receiptItem->purchaseOrderItem;
            $reqItem = $poItem?->requisitionItem;
            $code = $reqItem?->expenseCode?->code;
            if (! $poItem || ! $code || (float) $receiptItem->received_quantity <= 0) continue;

            // An event retry is a no-op before it touches the active commitment.
            if (CostLine::where('source_type', GoodsReceiptNoteItem::class)
                ->where('source_id', $receiptItem->id)->where('source_ref', 'accrual')->exists()) continue;

            $activeCommitment = CostLine::where('nature', CostLine::NATURE_COMMITTED)
                ->where('status', CostLine::STATUS_VERIFIED)
                ->where('details->purchase_order_item_id', $poItem->id)
                ->latest('id')->first();
            if ($activeCommitment) {
                $this->collector->releaseCommitment(
                    $activeCommitment, "Released by accepted receipt {$grn->grn_number}."
                );
            }

            $planned = $this->plannedLine($reqItem);
            $quantity = (string) $receiptItem->received_quantity;
            $amount = bcmul($quantity, (string) $poItem->unit_price, 2);
            $this->collector->postFromSource(new CostContext(
                expenseCode: $code, amount: $amount, nature: CostLine::NATURE_ACCRUED,
                enquiryId: $reqItem->project_enquiry_id ?: null,
                jobNumber: $grn->purchaseOrder?->requisition?->job_number,
                sourceType: GoodsReceiptNoteItem::class, sourceId: $receiptItem->id,
                sourceRef: 'accrual', incurredAt: (string) $grn->date,
                payeeType: 'SUPPLIER', payeeId: $grn->purchaseOrder?->supplier_id,
                payeeName: $grn->purchaseOrder?->supplier?->supplier_name,
                consumesLineId: $planned?->id,
                description: $poItem->custom_description ?: "Accepted goods: {$grn->grn_number}",
                details: [
                    'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                    'purchase_order_item_id' => $poItem->id,
                    'grn_number' => $grn->grn_number,
                    'quantity' => $quantity,
                    'unit_price' => $poItem->unit_price,
                ],
            ));

            $acceptedToDate = (string) GoodsReceiptNoteItem::where('purchase_order_item_id', $poItem->id)
                ->where('accepted', true)->sum('received_quantity');
            $remaining = bcsub((string) $poItem->quantity, $acceptedToDate, 3);
            if (bccomp($remaining, '0', 3) === 1) {
                $this->collector->postFromSource(new CostContext(
                    expenseCode: $code,
                    amount: bcmul($remaining, (string) $poItem->unit_price, 2),
                    nature: CostLine::NATURE_COMMITTED,
                    enquiryId: $reqItem->project_enquiry_id ?: null,
                    jobNumber: $grn->purchaseOrder?->requisition?->job_number,
                    sourceType: GoodsReceiptNoteItem::class, sourceId: $receiptItem->id,
                    sourceRef: 'remaining-commitment', incurredAt: (string) $grn->date,
                    payeeType: 'SUPPLIER', payeeId: $grn->purchaseOrder?->supplier_id,
                    payeeName: $grn->purchaseOrder?->supplier?->supplier_name,
                    consumesLineId: $planned?->id,
                    description: "Unreceived balance after {$grn->grn_number}",
                    details: [
                        'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                        'purchase_order_item_id' => $poItem->id,
                        'quantity' => $remaining,
                        'unit_price' => $poItem->unit_price,
                    ],
                ));
            }
            $posted++;
        }
        return $posted;
    }

    private function plannedLine(?object $item): ?CostLine
    {
        if (! $item?->budget_data_id) return null;
        $refs = array_filter([$item->budget_item_persistent_id, $item->budget_item_id]);
        return CostLine::where('source_type', 'BudgetLine')
            ->where('source_id', $item->budget_data_id)
            ->whereIn('source_ref', $refs ?: [''])->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_VERIFIED)->first();
    }
}
