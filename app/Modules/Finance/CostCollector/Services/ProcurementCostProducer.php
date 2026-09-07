<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use Illuminate\Support\Facades\Log;

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
            ['project_enquiry_id' => $enquiryId, 'job_number' => $jobNumber] =
                $this->identityFor($requisitionItem, $po->requisition);

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
                    'element' => $planned?->details['element'] ?? null,
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
            'items.inspection',
        ])->findOrFail($goodsReceiptNoteId);
        $posted = 0;

        foreach ($grn->items->where('accepted', true) as $receiptItem) {
            $poItem = $receiptItem->purchaseOrderItem;
            $reqItem = $poItem?->requisitionItem;
            $code = $reqItem?->expenseCode?->code;
            if ($receiptItem->stock_status === 'awaiting_inspection') continue;
            $effectiveQuantity = $receiptItem->inspection
                ? (float) $receiptItem->inspection->accepted_quantity
                : (float) $receiptItem->received_quantity;

            // Nothing to accrue: no order line to price it against, or nothing
            // accepted. Both are ordinary, and stay silent.
            if (! $poItem || $effectiveQuantity <= 0) continue;

            // A delivery with no expense code is still accrued.
            //
            // It used to be skipped by a bare `continue`, which is why nothing
            // ever reached Accrued Expenses; refusing it outright would be no
            // better, because the accrual is what debits Raw-material Inventory
            // on receipt. Withhold it and the issue that follows still credits
            // Inventory, relieving stock from a shelf the books never recorded
            // it arriving on — the negative balance this producer exists to
            // prevent.
            //
            // The journal does not need the code: both legs of an accrual are
            // fixed by its nature, Dr Inventory / Cr Accrued Expenses. What the
            // code carries is the VAT and WHT treatment, so a line without one
            // posts correctly and is simply not claimable. That is a gap for
            // Finance to close, and it is recorded as one rather than being
            // silently absorbed — the same way a Stores issue marks a material
            // that fell through to the default code.
            if (! $code) {
                Log::warning('Goods receipt line has no expense code; accrued without a tax treatment', [
                    'goods_receipt_note_id' => $grn->id,
                    'grn_number' => $grn->grn_number,
                    'goods_receipt_note_item_id' => $receiptItem->id,
                    'purchase_order_item_id' => $poItem->id,
                    'requisition_item_id' => $reqItem?->id,
                ]);
            }

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

            // Same resolution as the commitment path: a project id must never
            // reach the collector as an enquiry id, and the requisition's own
            // job_number is a display string rather than a resolvable one.
            ['project_enquiry_id' => $enquiryId, 'job_number' => $jobNumber] =
                $this->identityFor($reqItem, $grn->purchaseOrder?->requisition);

            $planned = $this->plannedLine($reqItem);
            $quantity = (string) $effectiveQuantity;
            $amount = bcmul($quantity, (string) $poItem->unit_price, 2);
            $this->collector->postFromSource(new CostContext(
                expenseCode: (string) ($code ?? ''), amount: $amount, nature: CostLine::NATURE_ACCRUED,
                enquiryId: $enquiryId,
                jobNumber: $jobNumber,
                sourceType: GoodsReceiptNoteItem::class, sourceId: $receiptItem->id,
                sourceRef: 'accrual', incurredAt: (string) $grn->date,
                payeeType: 'SUPPLIER', payeeId: $grn->purchaseOrder?->supplier_id,
                payeeName: $grn->purchaseOrder?->supplier?->supplier_name,
                consumesLineId: $planned?->id,
                description: $poItem->custom_description ?: "Accepted goods: {$grn->grn_number}",
                details: array_filter([
                    'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                    'element' => $planned?->details['element'] ?? null,
                    'purchase_order_item_id' => $poItem->id,
                    'grn_number' => $grn->grn_number,
                    // Marks the receipt as posted but unclaimable, so the gap is
                    // findable instead of looking like ordinary zero-rated spend.
                    'unclassified_expense_code' => $code ? null : true,
                    'quantity' => $quantity,
                    'unit_price' => $poItem->unit_price,
                    // Recorded so a later Stores issue of the same material can
                    // find this accrual and relieve it. Without it the two
                    // halves of the same delivery cannot be matched, and the job
                    // carries both the receipt and the issue.
                    'library_material_id' => $poItem->material_id,
                ], fn ($value) => $value !== null),
            ));

            $acceptedToDate = (string) GoodsReceiptNoteItem::with('inspection')
                ->where('purchase_order_item_id', $poItem->id)->where('accepted', true)->get()
                ->sum(fn ($line) => $line->stock_status === 'awaiting_inspection' ? 0 : ($line->inspection
                    ? (float) $line->inspection->accepted_quantity : (float) $line->received_quantity));
            $remaining = bcsub((string) $poItem->quantity, $acceptedToDate, 3);
            if (bccomp($remaining, '0', 3) === 1) {
                $this->collector->postFromSource(new CostContext(
                    expenseCode: $code,
                    amount: bcmul($remaining, (string) $poItem->unit_price, 2),
                    nature: CostLine::NATURE_COMMITTED,
                    enquiryId: $enquiryId,
                    jobNumber: $jobNumber,
                    sourceType: GoodsReceiptNoteItem::class, sourceId: $receiptItem->id,
                    sourceRef: 'remaining-commitment', incurredAt: (string) $grn->date,
                    payeeType: 'SUPPLIER', payeeId: $grn->purchaseOrder?->supplier_id,
                    payeeName: $grn->purchaseOrder?->supplier?->supplier_name,
                    consumesLineId: $planned?->id,
                    description: "Unreceived balance after {$grn->grn_number}",
                    details: array_filter([
                        'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                        'element' => $planned?->details['element'] ?? null,
                        'purchase_order_item_id' => $poItem->id,
                        'quantity' => $remaining,
                        'unit_price' => $poItem->unit_price,
                    ], fn ($value) => $value !== null),
                ));
            }
            $posted++;
        }

        return $posted;
    }

    /**
     * The enquiry and job number a purchase-order line is costed against.
     *
     * Two separate things used to hand a Projects primary key to a field that
     * means an enquiry id. The fallback here read `requisitions.project_id`
     * straight into `$enquiryId`; and `requisition_items.project_enquiry_id`
     * carries a project id of its own on rows written before that was noticed.
     * Project 196 exists and enquiry 196 does not, so every commitment on that
     * order died with "Enquiry #196 does not exist" and the whole purchase order
     * went unrecorded.
     *
     * StoresCostProducer::identityFor() settled the same question for inventory
     * movements, and the rule is copied rather than reinvented: **the project is
     * the authority**, and its enquiry and canonical job number are read off it.
     * That also repairs the job number, which on a requisition is a display
     * string — "WNG-03-2026-058 - LRP EFFACLAR STORE TAKEOVER" — and not
     * something the collector can resolve.
     *
     * A line's own `project_enquiry_id` is trusted only when an enquiry really
     * has that id. Anything left is handed over as a job number, never as an id,
     * because a guessed owner is worse than an unattributed cost.
     *
     * @return array{project_enquiry_id: ?int, job_number: ?string}
     */
    private function identityFor(?object $requisitionItem, ?object $requisition): array
    {
        if ($requisition?->project_id) {
            $project = Project::with('enquiry')->find($requisition->project_id);

            if ($project?->enquiry_id) {
                return [
                    'project_enquiry_id' => (int) $project->enquiry_id,
                    'job_number' => $project->enquiry?->job_number ?: $project->project_id,
                ];
            }
        }

        $candidate = $requisitionItem?->project_enquiry_id;

        if ($candidate && ProjectEnquiry::whereKey($candidate)->exists()) {
            return [
                'project_enquiry_id' => (int) $candidate,
                'job_number' => ProjectEnquiry::whereKey($candidate)->value('job_number'),
            ];
        }

        return [
            'project_enquiry_id' => null,
            'job_number' => blank($requisition?->job_number) ? null : $requisition->job_number,
        ];
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
