<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Invoice;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['purchaseOrder', 'supplier', 'createdBy']);

        // Date filtering
        if ($request->has('date_filter')) {
            $dateFilter = $request->input('date_filter');
            
            if ($dateFilter === 'today') {
                $query->whereDate('invoice_date', today());
            } elseif ($dateFilter === 'past_7_days') {
                $query->whereDate('invoice_date', '>=', now()->subDays(7));
            } elseif ($dateFilter === 'past_30_days') {
                $query->whereDate('invoice_date', '>=', now()->subDays(30));
            } elseif ($dateFilter === 'this_month') {
                $query->whereMonth('invoice_date', now()->month)
                      ->whereYear('invoice_date', now()->year);
            }
        }

        // Status filtering
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(20);

        return InvoiceResource::collection($invoices)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $invoices = Invoice::with(['purchaseOrder', 'supplier', 'createdBy'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('invoice_number', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhereHas('purchaseOrder', function ($q) use ($searchTerm) {
                        $q->where('po_number', 'LIKE', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('supplier', function ($q) use ($searchTerm) {
                        $q->where('supplier_name', 'LIKE', '%' . $searchTerm . '%');
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return InvoiceResource::collection($invoices)->preserveQuery();
    }

    public function store(Request $request)
    {
        $input = $request->all();
        
        $validator = Validator::make($input, [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($input['purchase_order_id']);
            
            $input['invoice_number'] = Invoice::generateInvoiceNumber();
            $input['supplier_id'] = $purchaseOrder->supplier_id;
            $input['user_id'] = auth()->id();

            $invoice = Invoice::create($input);

            return new InvoiceResource($invoice->load(['purchaseOrder', 'supplier', 'createdBy']));
        } catch (\Exception $e) {
            return response(['error' => 'Failed to create invoice: ' . $e->getMessage()], 500);
        }
    }

    public function show(Invoice $invoice)
    {
        return new InvoiceResource($invoice->load(['purchaseOrder', 'supplier', 'createdBy']));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $input = $request->all();
        
        $validator = Validator::make($input, [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'amount' => 'numeric|min:0',
            'status' => 'in:pending,paid,overdue,cancelled',
            'payment_date' => 'date|nullable',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        $invoice->update($input);

        return new InvoiceResource($invoice->load(['purchaseOrder', 'supplier', 'createdBy']));
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        
        return response(['message' => 'Invoice deleted successfully']);
    }

    public function recordPayment(Request $request, Invoice $invoice)
    {
        $validator = Validator::make($request->all(), [
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        $invoice->update([
            'status' => 'paid',
            'payment_date' => $request->payment_date,
            'payment_method' => $request->payment_method,
        ]);

        return new InvoiceResource($invoice->load(['purchaseOrder', 'supplier', 'createdBy']));
    }

    public function stats()
    {
        $totalInvoices = Invoice::count();
        $pendingAmount = Invoice::where('status', 'pending')->sum('amount');
        $paidAmount = Invoice::where('status', 'paid')->sum('amount');
        $overdueCount = Invoice::where('status', 'overdue')->count();

        return response([
            'total_invoices' => $totalInvoices,
            'pending_amount' => $pendingAmount,
            'paid_amount' => $paidAmount,
            'overdue_count' => $overdueCount,
        ]);
    }
}