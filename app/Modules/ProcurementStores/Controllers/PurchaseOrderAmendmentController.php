<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderAmendment;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * W2-4 (confirmed 2026-09-23, Option B): formal PO Amendment/Change-Order.
 *
 * STAB-3 already blocks a direct edit to any non-`pending` order — this is
 * the safe path to actually change one after approval. The original PO row
 * is never edited by this controller except when an amendment is actually
 * approved (commercial) or created (administrative, which applies on a
 * lighter, non-reapproval path) — in both cases the amendment record itself
 * remains the permanent, immutable account of what was proposed and by whom,
 * regardless of what happens to the order afterward.
 */
class PurchaseOrderAmendmentController extends Controller
{
    private const COMMERCIAL_HEADER_FIELDS = ['supplier_id'];
    private const ADMINISTRATIVE_HEADER_FIELDS = ['delivery_address', 'description', 'due_date', 'date'];

    public function index(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json(['data' => $purchaseOrder->amendments()
            ->with(['requestedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])
            ->get()]);
    }

    /**
     * Propose a change. Never invented: materiality is decided purely by
     * which fields actually changed — supplier, any item (material,
     * quantity, unit price, or the item set itself), or the resulting
     * order total are commercial; delivery address, description, due date
     * and date are administrative. Nothing here is a percentage or a KES
     * threshold, which WNG has not confirmed.
     */
    public function store(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::PROCUREMENT_ORDERS_CREATE), 403,
            'You do not have permission to propose changes to purchase orders.');
        abort_unless($purchaseOrder->status === 'approved', 422,
            'Only an approved order can be amended. A pending order is corrected directly, and one returned for correction uses that process instead.');

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'delivery_address' => 'nullable|string',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
            'date' => 'nullable|date',
            'items' => 'nullable|array|min:1',
            'items.*.id' => 'nullable|integer|exists:purchase_order_items,id',
            'items.*.material_id' => 'nullable|integer',
            'items.*.custom_description' => 'nullable|string',
            'items.*.quantity' => 'required_with:items|numeric|gt:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.uom_id' => 'nullable|integer',
        ]);

        $purchaseOrder->loadMissing('items');
        $original = $this->snapshot($purchaseOrder);
        $proposed = $this->proposedSnapshot($purchaseOrder, $validated);

        $changedFields = $this->changedFields($original, $proposed);
        if ($changedFields === []) {
            return response()->json(['error' => 'Nothing on this order would actually change.'], 422);
        }

        $isCommercial = collect($changedFields)->contains(
            fn ($field) => $field === 'items' || $field === 'total_amount' || in_array($field, self::COMMERCIAL_HEADER_FIELDS, true),
        );

        if ($isCommercial && in_array('items', $changedFields, true)) {
            $this->guardItemChangeIsSafe($purchaseOrder);
        }
        if ($isCommercial) {
            $this->guardRevisedTotalCoversBilled($purchaseOrder, $proposed['total_amount']);
        }

        $amendment = DB::transaction(function () use ($purchaseOrder, $request, $validated, $original, $proposed, $changedFields, $isCommercial) {
            $number = ((int) $purchaseOrder->amendments()->max('amendment_number')) + 1;

            $amendment = PurchaseOrderAmendment::create([
                'purchase_order_id' => $purchaseOrder->id,
                'amendment_number' => $number,
                'reason' => $validated['reason'],
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'is_commercial' => $isCommercial,
                'changed_fields' => $changedFields,
                'original_snapshot' => $original,
                'proposed_snapshot' => $proposed,
                'status' => $isCommercial ? 'pending' : 'approved',
                // An administrative correction is a lighter controlled path,
                // not an ungoverned one — the requester's own identity and
                // timestamp are the record, since no separate reapproval is
                // required by the confirmed direction.
                'approved_by' => $isCommercial ? null : $request->user()->id,
                'approved_at' => $isCommercial ? null : now(),
            ]);

            if (! $isCommercial) {
                $this->apply($purchaseOrder, $proposed);
            }

            return $amendment;
        });

        return response()->json([
            'message' => $isCommercial
                ? 'Amendment recorded and awaiting approval. The order\'s current approved terms remain in force until then.'
                : 'Administrative correction recorded and applied.',
            'data' => $amendment->load(['requestedBy:id,name', 'approvedBy:id,name']),
        ], 201);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderAmendment $amendment): JsonResponse
    {
        abort_unless((int) $amendment->purchase_order_id === (int) $purchaseOrder->id, 404);
        abort_unless(Gate::allows('amendOrder', $purchaseOrder), 403,
            'You do not have permission to approve purchase order amendments.');
        abort_unless($amendment->status === 'pending', 422, 'Only a pending amendment can be approved.');

        // Separation of duties: the same principle as approving the order
        // itself — whoever proposed the change does not also decide it.
        abort_if((int) $amendment->requested_by === (int) $request->user()->id, 422,
            'You proposed this amendment, so someone else has to approve it.');

        // Re-checked here, not just at store() time: receiving or billing
        // activity — or a payment — can have happened in the time between
        // proposing the amendment and it being approved. This is the
        // authoritative gate; store()'s own check is only early feedback.
        $itemsChanged = in_array('items', $amendment->changed_fields ?? [], true);
        if ($itemsChanged) {
            $this->guardItemChangeIsSafe($purchaseOrder);
        }
        $this->guardRevisedTotalCoversBilled($purchaseOrder, $amendment->proposed_snapshot['total_amount']);

        $originalItemIds = $purchaseOrder->items()->pluck('id')->all();

        DB::transaction(function () use ($purchaseOrder, $amendment, $request, $itemsChanged, $originalItemIds) {
            $this->apply($purchaseOrder, $amendment->proposed_snapshot);
            $amendment->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);

            if ($itemsChanged) {
                app(ProcurementCostProducer::class)->reconcileAmendedCommitments(
                    $purchaseOrder, $originalItemIds, $amendment->amendment_number
                );
            }
        });

        return response()->json([
            'message' => 'Amendment approved. The order now reflects the revised terms.',
            'data' => $amendment->fresh()->load(['requestedBy:id,name', 'approvedBy:id,name']),
        ]);
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderAmendment $amendment): JsonResponse
    {
        abort_unless((int) $amendment->purchase_order_id === (int) $purchaseOrder->id, 404);
        abort_unless(Gate::allows('amendOrder', $purchaseOrder), 403,
            'You do not have permission to reject purchase order amendments.');
        abort_unless($amendment->status === 'pending', 422, 'Only a pending amendment can be rejected.');

        $validated = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $amendment->update([
            'status' => 'rejected', 'rejected_by' => $request->user()->id,
            'rejected_at' => now(), 'rejection_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Amendment rejected. The order keeps its current approved terms.',
            'data' => $amendment->fresh()->load(['requestedBy:id,name', 'rejectedBy:id,name']),
        ]);
    }

    /**
     * W2-4/§9 closure gate: an item-level change (quantity, price, material,
     * or the item set itself) cannot be safely applied once the order has
     * any receiving or billing history. There is no WNG-confirmed treatment
     * for what happens to an already-posted accrual or a staged Bill when
     * the line it was measured against is revised underneath it, so this is
     * blocked outright rather than approximated — a supplier-only or other
     * non-item commercial change remains available regardless.
     */
    private function guardItemChangeIsSafe(PurchaseOrder $purchaseOrder): void
    {
        abort_if($purchaseOrder->goodsReceiptNotes()->exists(), 422,
            'This order already has goods received against it. Item-level changes (quantity, price, material) can no longer be safely amended — only non-item commercial changes, such as supplier, remain available.');
        abort_if($purchaseOrder->bills()->exists(), 422,
            'This order already has a supplier bill recorded against it. Item-level changes (quantity, price, material) can no longer be safely amended — only non-item commercial changes, such as supplier, remain available.');
    }

    /**
     * §9 closure gate: the revised order value must never fall below what
     * has already been validly billed against it — a downward amendment
     * that did would retroactively make historical billing exceed its own
     * commitment, which W2-3's cumulative cap exists specifically to
     * prevent going forward. No exception for this is confirmed, so it is
     * blocked rather than allowed through.
     */
    private function guardRevisedTotalCoversBilled(PurchaseOrder $purchaseOrder, string $revisedTotal): void
    {
        $billed = $purchaseOrder->totalBilled();
        abort_if(bccomp($revisedTotal, $billed, 2) < 0, 422,
            "The revised order value (KES {$revisedTotal}) cannot be less than the amount already billed against this order (KES {$billed}).");
    }

    /** The order's current commercial shape, as a plain array — never the Eloquent model itself. */
    private function snapshot(PurchaseOrder $order): array
    {
        return [
            'supplier_id' => $order->supplier_id,
            'delivery_address' => $order->delivery_address,
            'description' => $order->description,
            'due_date' => optional($order->due_date)->toDateString(),
            'date' => optional($order->date)->toDateString(),
            'total_amount' => (string) $order->total_amount,
            'items' => $order->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id,
                'material_id' => $item->material_id,
                'custom_description' => $item->custom_description,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'uom_id' => $item->uom_id,
            ])->values()->all(),
        ];
    }

    /** The original snapshot with only the fields the request actually supplied overlaid. */
    private function proposedSnapshot(PurchaseOrder $order, array $validated): array
    {
        $snapshot = $this->snapshot($order);

        foreach (self::COMMERCIAL_HEADER_FIELDS as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $snapshot[$field] = $validated[$field];
            }
        }
        foreach (self::ADMINISTRATIVE_HEADER_FIELDS as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $snapshot[$field] = $validated[$field];
            }
        }

        if (! empty($validated['items'])) {
            $snapshot['items'] = collect($validated['items'])->map(fn ($item) => [
                'id' => $item['id'] ?? null,
                'material_id' => $item['material_id'] ?? null,
                'custom_description' => $item['custom_description'] ?? null,
                'quantity' => (string) $item['quantity'],
                'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
                'uom_id' => $item['uom_id'] ?? null,
            ])->values()->all();
            $snapshot['total_amount'] = (string) collect($snapshot['items'])->reduce(
                fn ($sum, $item) => bcadd($sum, bcmul($item['quantity'], $item['unit_price'], 2), 2),
                '0.00',
            );
        }

        return $snapshot;
    }

    /** @return array<int, string> field names, "items" standing for the whole line set */
    private function changedFields(array $original, array $proposed): array
    {
        $changed = [];
        foreach ($original as $field => $value) {
            if ($field === 'items') {
                continue;
            }
            if ($proposed[$field] !== $value) {
                $changed[] = $field;
            }
        }
        if ($original['items'] !== $proposed['items']) {
            $changed[] = 'items';
        }

        return $changed;
    }

    /**
     * Apply an approved snapshot to the real order and its items. Never
     * called for a still-pending commercial amendment.
     *
     * Item identity is preserved wherever the snapshot still names an
     * existing item id — updated in place, never dropped and recreated.
     * A delete-and-recreate here would silently orphan every existing
     * Goods Receipt Note item and CostLine that points at that item's id
     * (a real defect this replaced, caught during Wave 2 closure
     * verification: even a purely administrative amendment, touching no
     * item at all, was destroying and regenerating every line's primary
     * key). Only an item genuinely dropped from the proposed set is
     * deleted; only one with no id at all is a new insert.
     */
    private function apply(PurchaseOrder $order, array $snapshot): void
    {
        $order->update([
            'supplier_id' => $snapshot['supplier_id'],
            'delivery_address' => $snapshot['delivery_address'],
            'description' => $snapshot['description'],
            'due_date' => $snapshot['due_date'],
            'date' => $snapshot['date'],
            'total_amount' => $snapshot['total_amount'],
        ]);

        $keepIds = collect($snapshot['items'])->pluck('id')->filter()->all();
        $order->items()->whereNotIn('id', $keepIds ?: [0])->delete();

        foreach ($snapshot['items'] as $item) {
            $attributes = [
                'material_id' => $item['material_id'],
                'custom_description' => $item['custom_description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total' => bcmul((string) $item['quantity'], (string) $item['unit_price'], 2),
                'uom_id' => $item['uom_id'],
            ];

            if (! empty($item['id'])) {
                $order->items()->whereKey($item['id'])->update($attributes);
            } else {
                $order->items()->create($attributes);
            }
        }
    }
}
