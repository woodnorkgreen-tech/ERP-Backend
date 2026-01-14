<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['items.material', 'supplier', 'createdBy']);

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

        $purchaseOrders = PurchaseOrder::with(['items.material', 'supplier', 'createdBy'])
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

    public function store(Request $request)
    {
        $input = $request->all();
        
        $validator = Validator::make($input, [
            'date' => 'required|date',
            'supplier_id' => 'required|exists:suppliers,id',
            'due_date' => 'required|date',
            'delivery_address' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:materials,id',
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
            return response(['error' => 'Failed to create purchase order: ' . $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy']));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $input = $request->all();
        
        $validator = Validator::make($input, [
            'date' => 'date',
            'supplier_id' => 'exists:suppliers,id',
            'due_date' => 'date',
            'status' => 'in:pending,approved,delivered,cancelled',
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

            return new PurchaseOrderResource($purchaseOrder->load(['items.material', 'supplier', 'createdBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to update purchase order: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();
        
        return response(['message' => 'Purchase order deleted successfully']);
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