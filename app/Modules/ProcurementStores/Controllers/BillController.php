<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use App\Http\Resources\BillResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class BillController extends Controller
{
    public function index(Request $request)
    {
        $query = Bill::with(['purchaseOrder', 'supplier', 'createdBy', 'payments']);

        if ($request->has('date_filter')) {
            $dateFilter = $request->input('date_filter');
            
            if ($dateFilter === 'today') {
                $query->whereDate('bill_date', today());
            } elseif ($dateFilter === 'past_7_days') {
                $query->whereDate('bill_date', '>=', now()->subDays(7));
            } elseif ($dateFilter === 'past_30_days') {
                $query->whereDate('bill_date', '>=', now()->subDays(30));
            } elseif ($dateFilter === 'this_month') {
                $query->whereMonth('bill_date', now()->month)
                      ->whereYear('bill_date', now()->year);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $bills = $query->orderBy('created_at', 'desc')->paginate(20);

        return BillResource::collection($bills)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $bills = Bill::with(['purchaseOrder', 'supplier', 'createdBy', 'payments'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('bill_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('purchaseOrder', function ($q) use ($searchTerm) {
                        $q->where('po_number', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('supplier', function ($q) use ($searchTerm) {
                        $q->where('supplier_name', 'LIKE', '%' . $searchTerm . '%');
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return BillResource::collection($bills)->preserveQuery();
    }

    public function getPendingBills(Request $request)
    {
        $query = Bill::with(['purchaseOrder', 'supplier'])
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('balance', '>', 0);

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $bills = $query->orderBy('due_date', 'asc')->get();

        return response()->json([
            'data' => $bills->map(function ($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'po_number' => $bill->purchaseOrder->po_number,
                    'supplier' => [
                        'id' => $bill->supplier->id,
                        'supplier_name' => $bill->supplier->supplier_name,
                    ],
                    'bill_date' => $bill->bill_date->format('Y-m-d'),
                    'due_date' => $bill->due_date->format('Y-m-d'),
                    'amount' => $bill->amount,
                    'paid_amount' => $bill->paid_amount,
                    'balance' => $bill->balance,
                    'status' => $bill->status,
                ];
            })
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->all();
        
        $validator = Validator::make($input, [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'bill_date' => 'required|date',
            'due_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($input['purchase_order_id']);
            
            if ($purchaseOrder->status !== 'approved') {
                return response(['error' => 'Only approved purchase orders can have bills'], 422);
            }
            
            if ($purchaseOrder->bills()->exists()) {
                return response(['error' => 'This purchase order already has a bill'], 422);
            }
            
            $input['bill_number'] = Bill::generateBillNumber();
            $input['supplier_id'] = $purchaseOrder->supplier_id;
            $input['user_id'] = auth()->id();
            $input['status'] = 'pending';

            $bill = Bill::create($input);

            return new BillResource($bill->load(['purchaseOrder', 'supplier', 'createdBy', 'payments']));
        } catch (\Exception $e) {
            return response(['error' => 'Failed to create bill: ' . $e->getMessage()], 500);
        }
    }

    public function show(Bill $bill)
    {
        return new BillResource($bill->load(['purchaseOrder', 'supplier', 'createdBy', 'payments.paymentMethod', 'payments.createdBy']));
    }

    public function recordPayment(Request $request, Bill $bill)
    {
        $validator = Validator::make($request->all(), [
            'amount_paid' => 'required|numeric|min:0.01|max:' . $bill->balance,
            'payment_date' => 'required|date',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            BillPayment::create([
                'bill_id' => $bill->id,
                'amount_paid' => $request->amount_paid,
                'payment_date' => $request->payment_date,
                'payment_method_id' => $request->payment_method_id,
                'notes' => $request->notes,
                'user_id' => auth()->id(),
            ]);

            return new BillResource($bill->fresh()->load(['purchaseOrder', 'supplier', 'createdBy', 'payments.paymentMethod']));
        } catch (\Exception $e) {
            return response(['error' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function stats()
    {
        $totalBills = Bill::count();
        $pendingAmount = Bill::whereIn('status', ['pending', 'partial', 'overdue'])->sum('balance');
        $paidAmount = Bill::where('status', 'paid')->sum('amount');
        $overdueCount = Bill::where('status', 'overdue')->count();

        return response([
            'total_bills' => $totalBills,
            'pending_amount' => $pendingAmount,
            'paid_amount' => $paidAmount,
            'overdue_count' => $overdueCount,
        ]);
    }

    public function getPaymentMethods()
    {
        $methods = PaymentMethod::where('is_active', true)->orderBy('method_name')->get();
        return response()->json(['data' => $methods]);
    }

    public function storePaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'method_name' => 'required|string|max:255|unique:payment_methods,method_name',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        $method = PaymentMethod::create([
            'method_name' => $request->method_name,
            'is_active' => true,
        ]);

        return response()->json(['data' => $method], 201);
    }
}