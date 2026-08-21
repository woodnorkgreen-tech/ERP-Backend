<?php

namespace App\Services;

use App\Models\TaskProcurementData;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProcurementOperationalSyncService
{
    private const SOURCE = 'procurement_stores';

    public function syncTask(int $taskId): ?TaskProcurementData
    {
        $procurementData = TaskProcurementData::where('enquiry_task_id', $taskId)->first();

        if (!$procurementData || !is_array($procurementData->procurement_items)) {
            return $procurementData;
        }

        $requisitionItems = $this->tracedRequisitionItemsForTask($taskId);
        $linksByIdentity = $this->buildLinksByIdentity($requisitionItems);
        $allLinks = collect($linksByIdentity)
            ->flatMap(fn (array $links) => $links)
            ->unique('requisitionItemId')
            ->values();

        $syncedItems = collect($procurementData->procurement_items)
            ->map(fn (array $item) => $this->syncItem($item, $linksByIdentity))
            ->values()
            ->all();

        $budgetSummary = $procurementData->budget_summary ?? [];
        $budgetSummary['operationalSync'] = $this->buildSummary($allLinks);

        $procurementData->procurement_items = $syncedItems;
        $procurementData->budget_summary = $budgetSummary;
        $procurementData->save();

        return $procurementData->fresh();
    }

    public function syncRequisition(Requisition|int $requisition): void
    {
        foreach ($this->taskIdsForRequisition($requisition) as $taskId) {
            $this->syncTask((int) $taskId);
        }
    }

    public function syncPurchaseOrder(PurchaseOrder|int $purchaseOrder): void
    {
        $purchaseOrder = $purchaseOrder instanceof PurchaseOrder
            ? $purchaseOrder
            : PurchaseOrder::find($purchaseOrder);

        if (!$purchaseOrder || !$purchaseOrder->requisition_id) {
            return;
        }

        $this->syncRequisition((int) $purchaseOrder->requisition_id);
    }

    public function syncBill(Bill|int $bill): void
    {
        $bill = $bill instanceof Bill ? $bill : Bill::find($bill);

        if (!$bill || !$bill->purchase_order_id) {
            return;
        }

        $this->syncPurchaseOrder((int) $bill->purchase_order_id);
    }

    public function syncGoodsReceiptNote(GoodsReceiptNote|int $goodsReceiptNote): void
    {
        $goodsReceiptNote = $goodsReceiptNote instanceof GoodsReceiptNote
            ? $goodsReceiptNote
            : GoodsReceiptNote::find($goodsReceiptNote);

        if (!$goodsReceiptNote || !$goodsReceiptNote->purchase_order_id) {
            return;
        }

        $this->syncPurchaseOrder((int) $goodsReceiptNote->purchase_order_id);
    }

    public function taskIdsForRequisition(Requisition|int $requisition): Collection
    {
        $requisitionId = $requisition instanceof Requisition ? $requisition->id : $requisition;

        return RequisitionItem::where('requisition_id', $requisitionId)
            ->whereNotNull('procurement_task_id')
            ->pluck('procurement_task_id')
            ->filter()
            ->unique()
            ->values();
    }

    private function tracedRequisitionItemsForTask(int $taskId): Collection
    {
        return RequisitionItem::query()
            ->where('procurement_task_id', $taskId)
            ->with([
                'requisition.purchaseOrder.supplier',
                'requisition.purchaseOrder.items.material',
                'requisition.purchaseOrder.items.goodsReceiptNoteItems.goodsReceiptNote',
                'requisition.purchaseOrder.items.goodsReceiptNoteItems.inspection',
                'requisition.purchaseOrder.bills.payments',
            ])
            ->get();
    }

    private function buildLinksByIdentity(Collection $requisitionItems): array
    {
        $linksByIdentity = [];

        foreach ($requisitionItems as $requisitionItem) {
            $link = $this->buildLink($requisitionItem);

            foreach ($this->identityCandidatesFromRequisitionItem($requisitionItem) as $identity) {
                $linksByIdentity[$identity][$requisitionItem->id] = $link;
            }
        }

        return $linksByIdentity;
    }

    private function buildLink(RequisitionItem $requisitionItem): array
    {
        $requisition = $requisitionItem->requisition;
        $purchaseOrder = $requisition?->purchaseOrder;
        $purchaseOrderItem = $purchaseOrder
            ? $this->resolvePurchaseOrderItem($requisitionItem, $purchaseOrder->items)
            : null;
        $bill = $purchaseOrder?->bills?->sortByDesc('created_at')->first();
        $receipt = $this->receiptState($purchaseOrderItem);
        $stage = $this->resolveStage($requisition, $purchaseOrder, $receipt['status']);

        return [
            'source' => self::SOURCE,
            'stage' => $stage,
            'requisitionItemId' => $requisitionItem->id,
            'requisitionId' => $requisition?->id,
            'requisitionNumber' => $requisition?->requisition_number,
            'requisitionStatus' => $requisition?->status,
            'requisitionDate' => optional($requisition?->date)->toDateString(),
            'quantity' => (float) $requisitionItem->quantity,
            'unitPrice' => (float) $requisitionItem->unit_price,
            'totalAmount' => (float) $requisitionItem->total,
            'purchaseOrderId' => $purchaseOrder?->id,
            'purchaseOrderItemId' => $purchaseOrderItem?->id,
            'poNumber' => $purchaseOrder?->po_number,
            'poStatus' => $purchaseOrder?->status,
            'supplierId' => $purchaseOrder?->supplier?->id,
            'supplierName' => $purchaseOrder?->supplier?->supplier_name,
            'expectedDeliveryDate' => optional($purchaseOrder?->due_date)->toDateString(),
            'billId' => $bill?->id,
            'billNumber' => $bill?->bill_number,
            'billStatus' => $bill?->status,
            'billAmount' => $bill ? (float) $bill->amount : null,
            'billPaidAmount' => $bill ? (float) $bill->paid_amount : null,
            'billBalance' => $bill ? (float) $bill->balance : null,
            'grnId' => $receipt['grnId'],
            'grnNumber' => $receipt['grnNumber'],
            'grnQualityCheck' => $receipt['qualityCheck'],
            'orderedQuantity' => $receipt['orderedQuantity'],
            'receivedQuantity' => $receipt['receivedQuantity'],
            'acceptedQuantity' => $receipt['acceptedQuantity'],
            'receiptStatus' => $receipt['status'],
            'lastSyncedAt' => now()->toISOString(),
        ];
    }

    private function resolvePurchaseOrderItem(RequisitionItem $requisitionItem, Collection $purchaseOrderItems): ?PurchaseOrderItem
    {
        $byTrace = $purchaseOrderItems->firstWhere('requisition_item_id', $requisitionItem->id);

        if ($byTrace) {
            return $byTrace;
        }

        $description = trim((string) $requisitionItem->custom_description);
        $unitPrice = (float) $requisitionItem->unit_price;

        return $purchaseOrderItems->first(function (PurchaseOrderItem $item) use ($requisitionItem, $description, $unitPrice) {
            $sameMaterial = (string) $item->material_id === (string) $requisitionItem->material_id;
            $sameDescription = trim((string) $item->custom_description) === $description;
            $sameQuantity = (float) $item->quantity === (float) $requisitionItem->quantity;
            $sameUnitPrice = (float) $item->unit_price === $unitPrice;

            return $sameMaterial && $sameDescription && $sameQuantity && $sameUnitPrice;
        });
    }

    private function receiptState(?PurchaseOrderItem $purchaseOrderItem): array
    {
        if (!$purchaseOrderItem) {
            return $this->emptyReceiptState();
        }

        $receiptItems = $purchaseOrderItem->goodsReceiptNoteItems ?? collect();

        if ($receiptItems->isEmpty()) {
            return $this->emptyReceiptState();
        }

        $orderedQuantity = (float) $receiptItems->sum('ordered_quantity');
        $receivedQuantity = (float) $receiptItems->sum('received_quantity');
        $acceptedQuantity = (float) $receiptItems
            ->filter(fn ($item) => (bool) $item->accepted && $item->stock_status !== 'awaiting_inspection')
            ->sum(fn ($item) => $item->inspection
                ? (float) $item->inspection->accepted_quantity
                : (float) $item->received_quantity);
        $latestReceipt = $receiptItems->sortByDesc('created_at')->first()?->goodsReceiptNote;

        if ($receivedQuantity <= 0) {
            $status = 'not_received';
        } elseif ($acceptedQuantity >= max(1, $orderedQuantity)) {
            $status = 'received';
        } elseif ($acceptedQuantity > 0) {
            $status = 'partially_received';
        } else {
            $status = 'quality_rejected';
        }

        return [
            'status' => $status,
            'grnId' => $latestReceipt?->id,
            'grnNumber' => $latestReceipt?->grn_number,
            'qualityCheck' => $latestReceipt?->quality_check,
            'orderedQuantity' => $orderedQuantity,
            'receivedQuantity' => $receivedQuantity,
            'acceptedQuantity' => $acceptedQuantity,
        ];
    }

    private function emptyReceiptState(): array
    {
        return [
            'status' => null,
            'grnId' => null,
            'grnNumber' => null,
            'qualityCheck' => null,
            'orderedQuantity' => 0.0,
            'receivedQuantity' => 0.0,
            'acceptedQuantity' => 0.0,
        ];
    }

    private function resolveStage(?Requisition $requisition, ?PurchaseOrder $purchaseOrder, ?string $receiptStatus): string
    {
        if (!$requisition) {
            return 'unlinked';
        }

        if ($requisition->status === 'rejected') {
            return 'requisition_rejected';
        }

        if (!$purchaseOrder) {
            return $requisition->status === 'approved'
                ? 'requisition_approved'
                : 'requisition_' . $requisition->status;
        }

        if ($purchaseOrder->status === 'cancelled') {
            return 'po_cancelled';
        }

        if ($receiptStatus === 'received') {
            return 'received';
        }

        if (in_array($receiptStatus, ['partially_received', 'quality_rejected'], true)) {
            return $receiptStatus;
        }

        return $purchaseOrder->status === 'approved'
            ? 'ordered'
            : 'po_' . $purchaseOrder->status;
    }

    private function syncItem(array $item, array $linksByIdentity): array
    {
        $links = $this->linksForItem($item, $linksByIdentity);

        if ($links->isEmpty()) {
            unset($item['procurementLinks'], $item['operationalSync'], $item['operationalStage']);
            return $item;
        }

        $state = $this->resolveItemState($links);
        $item['procurementLinks'] = $links->values()->all();
        $item['operationalStage'] = $state['stage'];
        $item['operationalSync'] = [
            'source' => self::SOURCE,
            'lastSyncedAt' => now()->toISOString(),
            'linkedRequisitionCount' => $links->pluck('requisitionId')->filter()->unique()->count(),
            'linkedPurchaseOrderCount' => $links->pluck('purchaseOrderId')->filter()->unique()->count(),
            'linkedBillCount' => $links->pluck('billId')->filter()->unique()->count(),
            'receivedQuantity' => (float) $links->sum('acceptedQuantity'),
            'committedAmount' => (float) $links
                ->reject(fn (array $link) => $link['requisitionStatus'] === 'rejected')
                ->sum('totalAmount'),
        ];

        $item['procurementStatus'] = $state['procurementStatus'];
        $item['availabilityStatus'] = $state['availabilityStatus'];
        $item['purchaseQuantity'] = $state['purchaseQuantity'];
        $item['purchaseOrderNumber'] = $state['purchaseOrderNumber'];
        $item['vendorName'] = $state['vendorName'];
        $item['expectedDeliveryDate'] = $state['expectedDeliveryDate'];
        $item['lastUpdated'] = now()->toISOString();

        return $item;
    }

    private function linksForItem(array $item, array $linksByIdentity): Collection
    {
        $links = collect();

        foreach ($this->identityCandidatesFromProcurementItem($item) as $identity) {
            if (!isset($linksByIdentity[$identity])) {
                continue;
            }

            $links = $links->merge(array_values($linksByIdentity[$identity]));
        }

        return $links->unique('requisitionItemId')->values();
    }

    private function resolveItemState(Collection $links): array
    {
        $activeLinks = $links->reject(fn (array $link) => $link['requisitionStatus'] === 'rejected');
        $hasReceived = $activeLinks->contains(fn (array $link) => $link['receiptStatus'] === 'received');
        $hasApprovedPurchaseOrder = $activeLinks->contains(fn (array $link) => $link['poStatus'] === 'approved');
        $hasPurchaseOrder = $activeLinks->contains(fn (array $link) => !empty($link['purchaseOrderId']));
        $allRejected = $links->isNotEmpty() && $activeLinks->isEmpty();

        if ($hasReceived) {
            $procurementStatus = 'received';
            $availabilityStatus = 'received';
            $stage = 'received';
        } elseif ($hasApprovedPurchaseOrder) {
            $procurementStatus = 'ordered';
            $availabilityStatus = 'ordered';
            $stage = 'ordered';
        } elseif ($hasPurchaseOrder) {
            $procurementStatus = 'pending';
            $availabilityStatus = 'ordered';
            $stage = $activeLinks->last()['stage'] ?? 'requisition_pending';
        } elseif ($activeLinks->isNotEmpty()) {
            $procurementStatus = 'pending';
            $availabilityStatus = 'available';
            $stage = $activeLinks->last()['stage'] ?? 'requisition_pending';
        } elseif ($allRejected) {
            $procurementStatus = 'cancelled';
            $availabilityStatus = 'cancelled';
            $stage = 'requisition_rejected';
        } else {
            $procurementStatus = 'pending';
            $availabilityStatus = 'available';
            $stage = 'pending';
        }

        $activePurchaseOrderNumbers = $activeLinks
            ->pluck('poNumber')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $supplierNames = $activeLinks
            ->pluck('supplierName')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $expectedDeliveryDate = $activeLinks
            ->pluck('expectedDeliveryDate')
            ->filter()
            ->sort()
            ->first();

        return [
            'stage' => $stage,
            'procurementStatus' => $procurementStatus,
            'availabilityStatus' => $availabilityStatus,
            'purchaseQuantity' => (float) $activeLinks->sum('quantity'),
            'purchaseOrderNumber' => $activePurchaseOrderNumbers,
            'vendorName' => $supplierNames,
            'expectedDeliveryDate' => $expectedDeliveryDate,
        ];
    }

    private function buildSummary(Collection $links): array
    {
        $activeLinks = $links->reject(fn (array $link) => $link['requisitionStatus'] === 'rejected');
        $uniqueBills = $activeLinks
            ->filter(fn (array $link) => !empty($link['billId']))
            ->unique('billId');

        return [
            'source' => self::SOURCE,
            'lastSyncedAt' => now()->toISOString(),
            'linkedRequisitionCount' => $links->pluck('requisitionId')->filter()->unique()->count(),
            'linkedPurchaseOrderCount' => $activeLinks->pluck('purchaseOrderId')->filter()->unique()->count(),
            'linkedBillCount' => $uniqueBills->count(),
            'committedAmount' => (float) $activeLinks->sum('totalAmount'),
            'billedAmount' => (float) $uniqueBills->sum('billAmount'),
            'paidAmount' => (float) $uniqueBills->sum('billPaidAmount'),
            'outstandingAmount' => (float) $uniqueBills->sum('billBalance'),
            'receivedQuantity' => (float) $activeLinks->sum('acceptedQuantity'),
            'openItemCount' => $activeLinks
                ->reject(fn (array $link) => in_array($link['receiptStatus'], ['received'], true))
                ->count(),
        ];
    }

    private function identityCandidatesFromRequisitionItem(RequisitionItem $item): array
    {
        return collect([
            $item->budget_item_persistent_id,
            $item->budget_item_id,
        ])->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function identityCandidatesFromProcurementItem(array $item): array
    {
        return collect([
            $item['budgetItemPersistentId'] ?? null,
            $item['budgetItemId'] ?? null,
            $item['budgetId'] ?? null,
        ])->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    public function safeSyncTask(int $taskId): void
    {
        try {
            $this->syncTask($taskId);
        } catch (\Throwable $exception) {
            Log::warning('Project procurement operational sync failed', [
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
