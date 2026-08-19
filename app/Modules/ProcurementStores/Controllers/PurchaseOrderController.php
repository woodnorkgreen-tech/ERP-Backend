<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Http\Controllers\Controller;
use App\Services\ProcurementOperationalSyncService;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderController extends Controller
{
    /**
     * Download Purchase Order as PDF
     */
    public function downloadPdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items.material', 'supplier', 'createdBy', 'approvedBy']);
        
        $pdf = Pdf::loadView('reports.procurement.purchase-order', [
            'po' => $purchaseOrder,
        ]);

        $filename = 'LPO-' . $purchaseOrder->po_number . '.pdf';
        
        return $pdf->download($filename);
    }
    /**
     * Check if user has approval/delete permissions
     * Only Super Admin, Admin, and Accounts roles can approve/delete
     */
    private function canApproveOrDelete()
    {
        $user = auth()->user();
        
        if (!$user || !$user->roles) {
            return false;
        }
        
        $allowedRoles = ['Super Admin', 'Admin', 'Accounts'];
        $userRoles = $user->roles->pluck('name')->toArray();
        
        foreach ($allowedRoles as $role) {
            if (in_array($role, $userRoles)) {
                return true;
            }
        }
        
        return false;
    }

    private function syncProjectProcurement(PurchaseOrder|int $purchaseOrder): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncPurchaseOrder($purchaseOrder);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from purchase order', [
                'purchase_order' => $purchaseOrder instanceof PurchaseOrder ? $purchaseOrder->id : $purchaseOrder,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['items.material', 'supplier', 'createdBy', 'approvedBy']);

        // Date filtering
        if ($request->has('date_filter')) {
            $dateFilter = $request->input('date_filter');

            if ($dateFilter === 'today') {
                $query->whereDate('date', today());
            } elseif ($dateFilter === 'past_7_days') {
                $query->whereDate('date', '>=', now()->subDays(7));
            } elseif ($dateFilter === 'past_30_days') {
                $query->whereDate('date', '>=', now()->subDays(30));
            } elseif ($dateFilter === 'this_month') {
                $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
            } elseif ($dateFilter === 'custom' && $request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            }
        }

        // Status filtering
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')->paginate(20);

        return PurchaseOrderResource::collection($purchaseOrders)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $purchaseOrders = PurchaseOrder::with(['items.material', 'supplier', 'createdBy', 'approvedBy'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('po_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('supplier', function ($q) use ($searchTerm) {
                        $q->where('supplier_name', 'LIKE', '%' . $searchTerm . '%');
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return PurchaseOrderResource::collection($purchaseOrders)->preserveQuery();
    }

    public function link(Requisition $requisition)
    {
        // Only approved requisitions can be linked
        if ($requisition->status !== 'approved') {
            return response(['error' => 'Only approved requisitions can be linked to purchase orders'], 403);
        }

        $requisition->load(['items.material', 'items.supplier', 'project', 'employee', 'department']);

        // An item counts as already ordered once it's on ANY purchase order —
        // a requisition can now spawn several POs (one per supplier), so we
        // check per item rather than blocking the whole requisition.
        $alreadyOrderedItemIds = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::whereIn(
            'requisition_item_id',
            $requisition->items->pluck('id')
        )->pluck('requisition_item_id')->all();

        if (count($alreadyOrderedItemIds) === $requisition->items->count()) {
            return response(['error' => 'Every item on this requisition already has a purchase order'], 403);
        }

        return response([
            'requisition' => $requisition,
            'already_ordered_item_ids' => $alreadyOrderedItemIds,
        ]);
    }

    // CREATE PO(s) FROM REQUISITION — one Purchase Order per supplier, so
    // items bound for different suppliers don't end up mixed on one order.
    public function storeLinked(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'requisition_id' => 'required|exists:requisitions,id',
            'due_date' => 'required|date',
            'delivery_address' => 'required|string',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $requisition = Requisition::with('items.supplier')->findOrFail($request->requisition_id);

            if ($requisition->status !== 'approved') {
                DB::rollBack();
                return response(['error' => 'Only approved requisitions can be linked'], 422);
            }

            // Skip items that were already placed on an earlier PO from this
            // same requisition (supports generating POs in more than one batch).
            $alreadyOrderedItemIds = \App\Modules\ProcurementStores\Models\PurchaseOrderItem::whereIn(
                'requisition_item_id',
                $requisition->items->pluck('id')
            )->pluck('requisition_item_id')->all();

            $pendingItems = $requisition->items->reject(
                fn ($item) => in_array($item->id, $alreadyOrderedItemIds)
            );

            if ($pendingItems->isEmpty()) {
                DB::rollBack();
                return response(['error' => 'Every item on this requisition already has a purchase order'], 422);
            }

            // Every remaining item needs a supplier chosen before it can go
            // on an order — that's decided back on the requisition, not here.
            $missingSupplier = $pendingItems->filter(fn ($item) => !$item->supplier_id);
            if ($missingSupplier->isNotEmpty()) {
                DB::rollBack();
                $names = $missingSupplier->map(
                    fn ($item) => $item->material?->material_name ?? $item->custom_description ?? "Item #{$item->id}"
                )->implode(', ');
                return response(['error' => "Choose a supplier for these items on the requisition first: {$names}"], 422);
            }

            $itemsBySupplier = $pendingItems->groupBy('supplier_id');
            $createdOrders = [];

            foreach ($itemsBySupplier as $supplierId => $items) {
                $purchaseOrder = PurchaseOrder::create([
                    'requisition_id' => $requisition->id,
                    'po_number' => PurchaseOrder::generatePONumber(),
                    'date' => now()->format('Y-m-d'),
                    'supplier_id' => $supplierId,
                    'due_date' => $request->due_date,
                    'delivery_address' => $request->delivery_address,
                    'description' => $request->description,
                    'status' => 'pending',
                    'user_id' => auth()->id(),
                ]);

                $totalAmount = 0;
                foreach ($items as $item) {
                    $unitPrice = (float) ($item->unit_price ?: ($item->material?->unit_cost ?? 0));
                    $total = $item->quantity * $unitPrice;

                    $purchaseOrder->items()->create([
                        'material_id'          => $item->material_id,
                        'requisition_item_id'  => $item->id,
                        'custom_description'   => $item->custom_description,
                        'quantity'             => $item->quantity,
                        'unit_price'           => $unitPrice,
                        'total'                => $total,
                    ]);

                    $totalAmount += $total;
                }

                $purchaseOrder->update(['total_amount' => $totalAmount]);
                $createdOrders[] = $purchaseOrder;
            }

            DB::commit();

            foreach ($createdOrders as $purchaseOrder) {
                $this->syncProjectProcurement($purchaseOrder);
            }

            $loaded = collect($createdOrders)->map(
                fn ($po) => new PurchaseOrderResource($po->load(['items.material', 'supplier', 'createdBy', 'requisition']))
            );

            return response(['data' => $loaded]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to create purchase order(s): ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $input = $request->all();

        $validator = Validator::make($input, [
            'date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'due_date' => 'required|date',
            'delivery_address' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:library_materials,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $input['po_number'] = PurchaseOrder::generatePONumber();
            $input['user_id'] = auth()->id();
            $input['status'] = 'pending'; // Always start as pending

            // Calculate total
            $totalAmount = 0;
            foreach ($input['items'] as $item) {
                $totalAmount += $item['quantity'] * $item['unit_price'];
            }
            $input['total_amount'] = $totalAmount;

            $items = $input['items'];
            unset($input['items']);

            $purchaseOrder = PurchaseOrder::create($input);

            foreach ($items as $item) {
                $item['total'] = $item['quantity'] * $item['unit_price'];
                $purchaseOrder->items()->create($item);
            }

            DB::commit();

            return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response([
                'error' => 'Purchase Order must be created from an approved requisition'
            ], 403);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy', 'approvedBy']));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $input = $request->all();

        $validator = Validator::make($input, [
            'date' => 'date',
            'supplier_id' => 'exists:suppliers,id',
            'due_date' => 'date',
            'items.*.requisition_item_id' => 'nullable|exists:requisition_items,id',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            if (isset($input['items'])) {
                $items = $input['items'];
                unset($input['items']);

                $purchaseOrder->items()->delete();

                $totalAmount = 0;
                foreach ($items as $item) {
                    $item['total'] = $item['quantity'] * $item['unit_price'];
                    $totalAmount += $item['total'];
                    $purchaseOrder->items()->create($item);
                }

                $input['total_amount'] = $totalAmount;
            }

            $purchaseOrder->update($input);

            DB::commit();

            $this->syncProjectProcurement($purchaseOrder);

            return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy', 'approvedBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to update purchase order: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        // ROLE RESTRICTION ADDED HERE
        if (!$this->canApproveOrDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can delete purchase orders.'
            ], 403);
        }

        // Only allow deletion if pending
        // if ($purchaseOrder->status !== 'pending') {
        //     return response(['error' => 'Only pending purchase orders can be deleted'], 422);
        // }

        $requisitionId = $purchaseOrder->requisition_id;
        $purchaseOrder->delete();

        if ($requisitionId) {
            try {
                app(ProcurementOperationalSyncService::class)->syncRequisition((int) $requisitionId);
            } catch (\Throwable $exception) {
                \Log::warning('Failed to sync project procurement after purchase order deletion', [
                    'requisition_id' => $requisitionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response(['message' => 'Purchase order deleted successfully']);
    }

    public function submitForApproval(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'pending') {
            return response(['error' => 'Only pending purchase orders can be submitted'], 422);
        }

        $purchaseOrder->submitForApproval();
        $this->syncProjectProcurement($purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy', 'approvedBy']));
    }

    public function approve(PurchaseOrder $purchaseOrder)
    {
        // ROLE RESTRICTION ADDED HERE
        if (!$this->canApproveOrDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can approve purchase orders.'
            ], 403);
        }

        if ($purchaseOrder->status !== 'pending_approval') {
            return response(['error' => 'Only pending purchase orders can be approved'], 422);
        }

        $purchaseOrder->approve(auth()->id());
        $this->syncProjectProcurement($purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy', 'approvedBy']));
    }

    public function getApprovedPurchaseOrders()
    {
        $purchaseOrders = PurchaseOrder::with(['supplier'])
            ->where('status', 'approved')
            ->whereDoesntHave('bills') // Only POs without bills
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $purchaseOrders->map(function ($po) {
                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'supplier' => [
                        'id' => $po->supplier->id,
                        'supplier_name' => $po->supplier->supplier_name,
                    ],
                    'total_amount' => $po->total_amount,
                    'due_date' => $po->due_date->format('Y-m-d'),
                ];
            })
        ]);
    }

    public function sendEmail(PurchaseOrder $purchaseOrder)
    {
        $supplier = $purchaseOrder->supplier;

        if (!$supplier->email) {
            return response(['error' => 'Supplier does not have an email address'], 422);
        }

        try {
            // TODO: Implement email sending logic
            // Mail::to($supplier->email)->send(new PurchaseOrderMail($purchaseOrder));

            return response(['message' => 'Purchase order sent to supplier email']);
        } catch (\Exception $e) {
            return response(['error' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }
}