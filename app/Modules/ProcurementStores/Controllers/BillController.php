<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillPayment;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Http\Resources\BillResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Constants\Permissions;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\Finance\Support\PaymentMethods;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use App\Modules\ProcurementStores\Services\SupplierInvoiceTax;
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
        $bill->load(['purchaseOrder', 'supplier', 'createdBy', 'payments.createdBy']);
        
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
        $query = Bill::with([
            'purchaseOrder', 'supplier', 'createdBy:id,name',
            // BillResource walks each payment's source and creator. Left to lazy
            // loading that is two queries per payment, so a 100-bill page paid
            // hundreds of round trips for two names.
            'payments.paymentSource:id,code,name,type', 'payments.createdBy:id,name',
        ]);

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

        $perPage = min(max($request->integer('perPage', $request->integer('per_page', 20)), 1), 100);
        $bills = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return BillResource::collection($bills)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = trim((string) $request->input('searchTerm', ''));
        $perPage = min(max($request->integer('perPage', $request->integer('per_page', 20)), 1), 100);

        $bills = Bill::with([
            'purchaseOrder', 'supplier', 'createdBy:id,name',
            // BillResource walks each payment's source and creator. Left to lazy
            // loading that is two queries per payment, so a 100-bill page paid
            // hundreds of round trips for two names.
            'payments.paymentSource:id,code,name,type', 'payments.createdBy:id,name',
        ])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('bill_number', 'LIKE', '%' . $searchTerm . '%')
                        ->orWhereHas('purchaseOrder', function ($q) use ($searchTerm) {
                            $q->where('po_number', 'LIKE', '%' . $searchTerm . '%');
                        })
                        ->orWhereHas('supplier', function ($q) use ($searchTerm) {
                            $q->where('supplier_name', 'LIKE', '%' . $searchTerm . '%');
                        });
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

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
            // Optional throughout: the invoice states its own VAT when it has
            // one, and the treatment prices it when it does not.
            'vat_amount' => 'nullable|numeric|min:0',
            'wht_amount' => 'nullable|numeric|min:0',
            'etims_invoice_no' => 'nullable|string|max:64',
            'supplier_pin' => 'nullable|string|max:20',
            'tax_point_date' => 'nullable|date',
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

            /*
             * Priced after creation rather than before, because the split needs
             * the supplier and the order the bill was just attached to. The
             * invoice is the tax point for the procurement rail — nothing
             * earlier in the chain carries VAT, so if it is not captured here it
             * is not captured at all.
             */
            $bill->forceFill(app(SupplierInvoiceTax::class)->priceFor($bill->fresh(), $input))->save();
            $bill->refresh();
            $bill->updatePaymentStatus();

            $this->syncProjectProcurementFromBill($bill);

            return new BillResource($bill->load([
            'purchaseOrder', 'supplier', 'createdBy:id,name',
            // BillResource walks each payment's source and creator. Left to lazy
            // loading that is two queries per payment, so a 100-bill page paid
            // hundreds of round trips for two names.
            'payments.paymentSource:id,code,name,type', 'payments.createdBy:id,name',
        ]));
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

        /*
         * Verification is the accounting event, so it is where the liability
         * moves from "accrued against a receipt" to "owed on a named invoice".
         *
         * The sign-off and its journal are one transaction on purpose. Posting
         * after a committed save would leave the failure case looking exactly
         * like the defect being fixed — a bill reading verified while Accounts
         * Payable knows nothing about it — except now with an error message
         * claiming otherwise. Either both land or neither does, and Accounts
         * hears about a closed period or an unmapped account while the invoice
         * is still theirs to fix.
         */
        try {
            DB::transaction(function () use ($bill, $state, $request) {
                $bill->forceFill([
                    'verified_by' => auth()->id(),
                    'verified_at' => now(),
                    'verification_basis' => 'three_way_match',
                    'verification_fingerprint' => $state['fingerprint'],
                    'verification_notes' => $request->input('verification_notes'),
                ])->save();

                app(JournalPostingService::class)->postSupplierInvoice($bill->fresh());
            });
        } catch (\Throwable $e) {
            return response([
                'error' => 'The invoice matched, but it could not be posted to the ledger: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice verified against the order and the accepted receipt.',
            'data' => $workflow->bill($bill->fresh()),
        ]);
    }

    /**
     * May this person settle this invoice on the spot, without a requisition?
     *
     * Every other shilling that leaves WNG's accounts is either a requisition
     * somebody approved, or an exceptional direct payment a second person
     * released from the approval inbox. Paying a supplier used to be neither: no
     * approval, no permission beyond being logged in.
     *
     * The exception this restores is narrow and has a reason behind it. A bill
     * verified by three-way match has already been checked independently —
     * against the order and against what Stores accepted — so the second pair of
     * eyes exists; requiring a requisition as well would only re-approve a
     * decision already made. A `legacy` bill has had no such check: it predates
     * the match and was never reconciled to a goods receipt, so it goes through
     * a requisition like any other spend.
     *
     * The permission is the petty-cash disbursement one on purpose. Since the
     * payment architecture was unified there is one `payments` table, so "may
     * move money out of an account" should be one permission rather than one per
     * screen that happens to move it.
     *
     * @return string|null the reason to refuse, or null to allow
     */
    private function directPaymentRefusal(Request $request, Bill $bill): ?string
    {
        if (! $request->user()?->can(Permissions::FINANCE_PETTY_CASH_CREATE)) {
            return 'You are not authorised to pay supplier invoices. Raise a petty cash requisition instead.';
        }

        /*
         * Only the legacy case is refused here. An invoice that is simply not
         * verified yet falls through to SupplierPaymentGuard, which already
         * refuses it and names the checks that failed — a better answer than
         * this one, and the answer the screens are built around.
         */
        if ($bill->verification_basis === 'legacy') {
            return "Invoice {$bill->bill_number} predates the three-way match and was never reconciled to a "
                . 'goods receipt, so it cannot be paid directly. Raise a petty cash requisition against it, '
                . 'which goes through approval.';
        }

        return null;
    }

    public function recordPayment(Request $request, Bill $bill)
    {
        if ($refusal = $this->directPaymentRefusal($request, $bill)) {
            return response(['error' => $refusal], 403);
        }

        $validator = Validator::make($request->all(), [
            'amount_paid' => 'required|numeric|min:0.01|max:' . $bill->balance,
            'payment_date' => 'required|date',
            // Two independent facts: which account the money left, and how it
            // was transmitted. The account is required because it decides the
            // credit leg; the method is required because nothing else records
            // whether this was a cheque or an RTGS.
            'payment_source_id' => ['required', Rule::exists('payment_sources', 'id')->where('is_active', true)],
            'payment_method' => ['required', Rule::in(PaymentMethods::values())],
            'reference_number' => 'nullable|required_unless:payment_method,cash|string|max:255',
            // What the bank or M-Pesa charged us to send it. On top of the
            // invoice, never part of it.
            'transaction_cost' => 'nullable|numeric|min:0|max:999999.99',
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
            app(\App\Modules\ProcurementStores\Services\SupplierPaymentService::class)->record($bill, [
                'bill_id' => $bill->id,
                'amount_paid' => $request->amount_paid,
                'payment_date' => $request->payment_date,
                'payment_method' => $request->payment_method,
                'payment_source_id' => $request->payment_source_id,
                'reference_number' => $request->reference_number,
                'transaction_cost' => $request->transaction_cost ?? 0,
                'user_id' => auth()->id(),
            ]);

            $this->syncProjectProcurementFromBill($bill->id);

            return new BillResource($bill->fresh()->load(['purchaseOrder', 'supplier', 'createdBy', 'verifiedBy', 'payments.createdBy']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response(['error' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function recordMultiBillPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bill_ids' => 'required|array|min:1',
            'bill_ids.*' => 'integer|distinct|exists:bills,id',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            // Same gate as the single-bill path above. A batch payment is the
            // one place a control is most likely to be missed.
            'payment_source_id' => ['required', Rule::exists('payment_sources', 'id')->where('is_active', true)],
            'payment_method' => ['required', Rule::in(PaymentMethods::values())],
            'reference_number' => 'nullable|required_unless:payment_method,cash|string|max:255',
            // One transfer, one charge — however many invoices it clears.
            'transaction_cost' => 'nullable|numeric|min:0|max:999999.99',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $bills = Bill::whereIn('id', $request->bill_ids)
                        ->where('balance', '>', 0)
                        ->orderBy('due_date', 'asc')
                        ->lockForUpdate()->get();

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

                // Direct payment is the exception three-way verification earns.
                // Checked per invoice rather than once for the run: a batch is
                // exactly where one unverified invoice would ride along.
                if ($refusal = $this->directPaymentRefusal($request, $candidate)) {
                    DB::rollBack();
                    return response(['error' => $refusal], 403);
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

            /*
             * The money moves once. Oldest invoice first until the amount is
             * used up, and the whole lot is handed to the service as a single
             * payment with several allocations — which is what gives the
             * transaction fee somewhere to sit. Recording an invoice at a time
             * made one bank transfer into three payment documents, and a fee
             * that belongs to the transfer could then only be guessed at.
             */
            $remainingPayment = (float) $request->amount_paid;
            $allocations = [];
            $previousBalances = [];

            foreach ($bills as $bill) {
                if ($remainingPayment <= 0) {
                    break;
                }

                $amountForThisBill = min($remainingPayment, (float) $bill->balance);
                $previousBalances[$bill->id] = (float) $bill->balance;
                $allocations[] = ['bill' => $bill, 'amount' => $amountForThisBill];
                $remainingPayment -= $amountForThisBill;
            }

            $recorded = app(\App\Modules\ProcurementStores\Services\SupplierPaymentService::class)
                ->recordBatch($allocations, [
                    'payment_date' => $request->payment_date,
                    'payment_method' => $request->payment_method,
                    'payment_source_id' => $request->payment_source_id,
                    'reference_number' => $request->reference_number,
                    'transaction_cost' => $request->transaction_cost ?? 0,
                    'user_id' => auth()->id(),
                ]);

            $billsUpdated = [];
            $paymentCodes = [];

            foreach ($recorded as $billPayment) {
                $paid = $billPayment->bill;
                $paymentCodes[] = $billPayment->payment_code;

                $billsUpdated[] = [
                    'bill_id' => $paid->id,
                    'bill_number' => $paid->bill_number,
                    'amount_paid' => (float) $billPayment->amount_paid,
                    'previous_balance' => $previousBalances[$paid->id] ?? null,
                    'new_balance' => (float) $paid->fresh()->balance,
                ];
            }

            DB::commit();

            foreach ($billsUpdated as $billUpdate) {
                $this->syncProjectProcurementFromBill((int) $billUpdate['bill_id']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully for ' . count($billsUpdated) . ' bill(s)',
                'payment_code' => count($paymentCodes) === 1 ? $paymentCodes[0] : null,
                'payment_codes' => $paymentCodes,
                'total_paid' => (float) $request->amount_paid,
                'bills_updated' => $billsUpdated,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response(['error' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    public function stats()
    {
        // One pass over the table instead of four. The per-status counts are
        // returned here as well: the dashboard used to fetch a hundred fully
        // hydrated bills — each dragging its purchase order, requisition,
        // project, line items and payments across the wire — purely to count
        // three status buckets the database can count in place.
        $totals = Bill::query()
            ->selectRaw("
                COUNT(*) AS total_bills,
                COALESCE(SUM(CASE WHEN status IN ('pending', 'partial', 'overdue') THEN balance ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS paid_amount,
                COALESCE(SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END), 0) AS overdue_count,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
                COALESCE(SUM(CASE WHEN status IN ('partial', 'partially_paid') THEN 1 ELSE 0 END), 0) AS partially_paid_count,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count
            ")
            ->first();

        return response()->json([
            'total_bills' => (int) $totals->total_bills,
            'pending_amount' => (float) $totals->pending_amount,
            'paid_amount' => (float) $totals->paid_amount,
            'overdue_count' => (int) $totals->overdue_count,
            'paid_count' => (int) $totals->paid_count,
            'partially_paid_count' => (int) $totals->partially_paid_count,
            'unpaid_count' => (int) $totals->unpaid_count,
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
}
