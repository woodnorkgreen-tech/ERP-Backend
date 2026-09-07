<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PaymentMethod;
use App\Http\Resources\BillResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use App\Modules\ProcurementStores\Services\SupplierPaymentGuard;
use App\Services\ProcurementOperationalSyncService;
use Barryvdh\DomPDF\Facade\Pdf;

class BillController extends Controller
{
    /**
     * Download Bill as PDF
     */
    public function downloadPdf(Bill $bill)
    {
        $bill->load(['purchaseOrder', 'supplier', 'createdBy', 'payments.paymentMethod', 'payments.createdBy']);
        
        $pdf = Pdf::loadView('reports.procurement.bill', [
            'bill' => $bill,
        ]);

        $filename = 'Bill-' . $bill->bill_number . '.pdf';
        
        return $pdf->download($filename);
    }
    /**
     * Check if user has delete permissions
     * Only Super Admin, Admin, and Accounts roles can delete
     */
    private function canDelete()
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

    /**
     * Who may sign off a supplier invoice for payment.
     *
     * Deliberately the same list that may delete a bill: verification is the
     * decision that releases money, so it answers to Accounts rather than to
     * whoever can reach the screen. Self-verification is recorded rather than
     * blocked — Accounts here is one or two people, and a maker-checker split
     * would simply deadlock them. `verified_by` is what makes the separation
     * auditable when the business is ready to enforce it.
     */
    private function canVerify(): bool
    {
        return $this->canDelete();
    }

    private function syncProjectProcurementFromBill(Bill|int $bill): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncBill($bill);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from bill', [
                'bill' => $bill instanceof Bill ? $bill->id : $bill,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function syncProjectProcurementFromPurchaseOrder(int $purchaseOrderId): void
    {
        try {
            app(ProcurementOperationalSyncService::class)->syncPurchaseOrder($purchaseOrderId);
        } catch (\Throwable $exception) {
            \Log::warning('Failed to sync project procurement from bill purchase order', [
                'purchase_order_id' => $purchaseOrderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

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

    public function getPendingBills(Request $request, PurchaseOrderWorkflow $workflow)
    {
        // The workflow reads the order's lines, receipts and supplier for every
        // row; loading them here keeps a page of payables to one round of queries.
        $query = Bill::with([
            'purchaseOrder.items.goodsReceiptNoteItems.inspection',
            'purchaseOrder.goodsReceiptNotes',
            'purchaseOrder.bills',
            'purchaseOrder.supplier',
            'supplier',
            'verifiedBy',
        ])
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('balance', '>', 0);

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $bills = $query->orderBy('due_date', 'asc')->get();

        return response()->json([
            /*
             * Each row carries whether the gate would accept it, so a payment
             * screen can offer only what it can actually settle rather than
             * asking about each invoice one at a time.
             */
            'data' => $bills->map(function ($bill) use ($workflow) {
                $state = $workflow->bill($bill);

                return [
                    'can_pay' => $state['can_pay'],
                    'verified' => $state['verified'],
                    'blockers' => $state['blockers'],
                    'supplier_invoice_number' => $bill->supplier_invoice_number,
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'purchase_order_id' => $bill->purchase_order_id,
                    'po_number' => $bill->purchaseOrder->po_number,
                    'supplier' => [
                        'id' => $bill->supplier->id,
                        'supplier_name' => $bill->supplier->supplier_name,
                    ],
                    'bill_date' => $bill->bill_date->format('Y-m-d'),
                    'due_date' => $bill->due_date->format('Y-m-d'),
                    'amount' => (float) $bill->amount,
                    'paid_amount' => (float) $bill->paid_amount,
                    'balance' => (float) $bill->balance,
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
            'supplier_invoice_number' => 'required|string|max:120',
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
            $this->syncProjectProcurementFromBill($bill);

            return new BillResource($bill->load(['purchaseOrder', 'supplier', 'createdBy', 'payments']));
        } catch (\Exception $e) {
            return response(['error' => 'Failed to create bill: ' . $e->getMessage()], 500);
        }
    }

    public function show(Bill $bill)
    {
        return new BillResource($bill->load([
        'purchaseOrder.items.material',
        'purchaseOrder.requisition.project',
        'purchaseOrder.requisition.department',
        'purchaseOrder.requisition.projectEnquiry',
        'supplier',
        'createdBy',
        'verifiedBy',
        'payments.paymentMethod',
        'payments.createdBy'
    ]));
    }

    /**
     * Where this invoice stands, and what stops it being paid. The bill screen,
     * the purchase order screen and the payment gate all read this same answer.
     */
    public function verification(Bill $bill, PurchaseOrderWorkflow $workflow)
    {
        return response()->json(['data' => $workflow->bill($bill)]);
    }

    /**
     * Accounts signs off the three-way match. The sign-off is stamped with a
     * fingerprint of what was checked, so a later change to the order, the
     * receipt or the invoice withdraws it instead of carrying it forward.
     */
    public function verify(Request $request, Bill $bill, PurchaseOrderWorkflow $workflow)
    {
        if (! $this->canVerify()) {
            return response([
                'error' => 'Only Accounts can verify a supplier invoice for payment.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'supplier_invoice_number' => 'nullable|string|max:120',
            'verification_notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        if ($request->filled('supplier_invoice_number')) {
            $bill->supplier_invoice_number = trim($request->input('supplier_invoice_number'));
            $bill->save();
            $bill->refresh();
        }

        $state = $workflow->bill($bill);

        if (! $state['eligible_for_verification']) {
            return response([
                'error' => 'This invoice does not yet pass the three-way match.',
                'blockers' => $state['blockers'],
                'checks' => $state['checks'],
            ], 422);
        }

        $bill->forceFill([
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'verification_basis' => 'three_way_match',
            'verification_fingerprint' => $state['fingerprint'],
            'verification_notes' => $request->input('verification_notes'),
        ])->save();

        return response()->json([
            'message' => 'Invoice verified against the order and the accepted receipt.',
            'data' => $workflow->bill($bill->fresh()),
        ]);
    }

    public function recordPayment(Request $request, Bill $bill)
    {
        $validator = Validator::make($request->all(), [
            'amount_paid' => 'required|numeric|min:0.01|max:' . $bill->balance,
            'payment_date' => 'required|date',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'reference_number' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            app(SupplierPaymentGuard::class)->assertPayable($bill, (string) $request->amount_paid);
        } catch (\RuntimeException $blocked) {
            return response(['error' => $blocked->getMessage()], 422);
        }

        try {
            $paymentCode = 'PAY-' . str_pad((BillPayment::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);

            BillPayment::create([
                'bill_id' => $bill->id,
                'payment_code' => $paymentCode,
                'amount_paid' => $request->amount_paid,
                'payment_date' => $request->payment_date,
                'payment_method_id' => $request->payment_method_id,
                'reference_number' => $request->reference_number,
                'user_id' => auth()->id(),
            ]);

            $this->syncProjectProcurementFromBill($bill->id);

            return new BillResource($bill->fresh()->load(['purchaseOrder', 'supplier', 'createdBy', 'verifiedBy', 'payments.paymentMethod', 'payments.createdBy']));
        } catch (\Exception $e) {
            return response(['error' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function recordMultiBillPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bill_ids' => 'required|array|min:1',
            'bill_ids.*' => 'exists:bills,id',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'reference_number' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $bills = Bill::whereIn('id', $request->bill_ids)
                        ->where('balance', '>', 0)
                        ->orderBy('due_date', 'asc')
                        ->get();

            if ($bills->isEmpty()) {
                DB::rollBack();
                return response(['error' => 'No bills with outstanding balance found'], 422);
            }

            /*
             * A batch run is refused whole rather than in part. Paying the
             * clear invoices and silently dropping the blocked ones would put
             * the reference number on a total that no longer matches what left
             * the bank.
             */
            $guard = app(SupplierPaymentGuard::class);
            $blocked = [];
            foreach ($bills as $candidate) {
                $state = $guard->evaluate($candidate);
                if (! $state['payable']) {
                    $blocked[] = $candidate->bill_number . ': ' . implode(' ', $state['blockers']);
                }
            }

            if ($blocked !== []) {
                DB::rollBack();
                return response([
                    'error' => 'Some invoices in this batch are not cleared for payment.',
                    'blocked' => $blocked,
                ], 422);
            }

            $totalBalance = $bills->sum('balance');
            
            if ($request->amount_paid > $totalBalance) {
                DB::rollBack();
                return response(['error' => 'Payment amount (' . number_format($request->amount_paid, 2) . ') exceeds total balance (' . number_format($totalBalance, 2) . ')'], 422);
            }

            $paymentCode = 'PAY-' . str_pad((BillPayment::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);
            $remainingPayment = $request->amount_paid;
            $billsUpdated = [];

            foreach ($bills as $bill) {
                if ($remainingPayment <= 0) {
                    break;
                }

                $amountForThisBill = min($remainingPayment, $bill->balance);
                
                BillPayment::create([
                    'bill_id' => $bill->id,
                    'payment_code' => $paymentCode,
                    'amount_paid' => $amountForThisBill,
                    'payment_date' => $request->payment_date,
                    'payment_method_id' => $request->payment_method_id,
                    'reference_number' => $request->reference_number,
                    'user_id' => auth()->id(),
                ]);

                $billsUpdated[] = [
                    'bill_id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'amount_paid' => (float) $amountForThisBill,
                    'previous_balance' => (float) $bill->balance,
                    'new_balance' => (float) $bill->fresh()->balance,
                ];

                $remainingPayment -= $amountForThisBill;
            }

            DB::commit();

            foreach ($billsUpdated as $billUpdate) {
                $this->syncProjectProcurementFromBill((int) $billUpdate['bill_id']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully for ' . count($billsUpdated) . ' bill(s)',
                'payment_code' => $paymentCode,
                'total_paid' => (float) $request->amount_paid,
                'bills_updated' => $billsUpdated,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function stats()
    {
        $totalBills = Bill::count();
        $pendingAmount = Bill::whereIn('status', ['pending', 'partial', 'overdue'])->sum('balance');
        $paidAmount = Bill::where('status', 'paid')->sum('amount');
        $overdueCount = Bill::where('status', 'overdue')->count();

        return response()->json([
            'total_bills' => $totalBills,
            'pending_amount' => (float) $pendingAmount,
            'paid_amount' => (float) $paidAmount,
            'overdue_count' => $overdueCount,
        ]);
    }

    /**
     * Delete bill - RESTRICTED to Super Admin, Admin, and Accounts
     */
    public function destroy(Bill $bill)
    {
        // Check authorization
        if (!$this->canDelete()) {
            return response([
                'error' => 'Unauthorized. Only Super Admin, Admin, and Accounts can delete bills.'
            ], 403);
        }

        try {
            $purchaseOrderId = $bill->purchase_order_id;
            $bill->delete();

            if ($purchaseOrderId) {
                $this->syncProjectProcurementFromPurchaseOrder((int) $purchaseOrderId);
            }

            return response(['message' => 'Bill deleted successfully']);
        } catch (\Exception $e) {
            return response(['error' => 'Failed to delete bill: ' . $e->getMessage()], 500);
        }
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
