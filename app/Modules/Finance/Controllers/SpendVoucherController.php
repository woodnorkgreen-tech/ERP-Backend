<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Models\SpendVoucherAllocation;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\SpendVoucherSettlementService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\HR\Models\HRAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SpendVoucherController extends Controller
{
    public function __construct(
        private JournalPostingService $journalPostingService,
        private SpendVoucherSettlementService $settlementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $query = SpendVoucher::with('paymentSource')->withCount('costLines')
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $status = $request->query('status');
            // Clients that still filter on `draft` mean "awaiting approval".
            if (in_array($status, ['draft', 'pending_approval'], true)) {
                $query->whereIn('status', ['draft', 'pending_approval']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->has('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('voucher_no', 'like', "%{$search}%")
                  ->orWhere('payee_name', 'like', "%{$search}%")
                  ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        $vouchers = $query->paginate($request->query('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $vouchers->items(),
            'meta' => [
                'current_page' => $vouchers->currentPage(),
                'last_page' => $vouchers->lastPage(),
                'per_page' => $vouchers->perPage(),
                'total' => $vouchers->total(),
            ],
            // Aggregated over every voucher, not the page. The client used to
            // derive these by reducing whatever rows the first page happened to
            // contain, so with more than 25 vouchers the headline figures were
            // simply wrong — and wrong in a way that looked plausible.
            'summary' => $this->summary(),
        ]);
    }

    /**
     * Headline counts and the posted total, across all vouchers.
     *
     * Deliberately unfiltered: these are the totals the tabs are counting, so
     * they must not move when a tab is selected.
     */
    private function summary(): array
    {
        $counts = SpendVoucher::query()
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $awaiting = (int) ($counts['pending_approval']->count ?? 0)
            + (int) ($counts['draft']->count ?? 0);

        return [
            'total' => (int) $counts->sum('count'),
            // Awaiting approval. `draft` is retained only so older clients that
            // still read that key keep showing a non-zero queue count.
            'pending_approval' => $awaiting,
            'draft' => $awaiting,
            'approved' => (int) ($counts['approved']->count ?? 0),
            'posted' => (int) ($counts['posted']->count ?? 0),
            'posted_amount' => number_format((float) ($counts['posted']->amount ?? 0), 2, '.', ''),
        ];
    }

    /** Verified, journalised liabilities which have not already been paid. */
    public function eligibleLiabilities(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE), 403);

        $controlAccounts = ChartOfAccount::postable()->whereIn('code', ChartAccountMap::localMany(['2100', '2150']))->pluck('id');
        $lines = CostLine::query()
            ->withReferenceNames()
            ->with(['expenseCode:id,code,expense_type'])
            ->where('status', CostLine::STATUS_VERIFIED)
            ->whereNotNull('journal_entry_id')
            ->whereHas('journalEntry', function ($journal) use ($controlAccounts) {
                $journal->where('status', 'posted')->whereHas('lines', fn ($line) =>
                    $line->where('entry_type', 'credit')->whereIn('account_id', $controlAccounts)
                );
            })
            ->orderBy('incurred_at')
            ->limit(500)
            ->get()
            ->map(function (CostLine $line) {
                $payable = $this->payableAmount($line);
                $allocated = $this->activeAllocatedAmount($line->id);
                $remaining = bcsub($payable, $allocated, 2);

                $fundingMode = $line->details['funding_mode'] ?? (
                    ! empty($line->submitted_by_user_id) && empty($line->payee_supplier_name)
                        ? 'out_of_pocket'
                        : 'unpaid_invoice'
                );
                $claimantName = $line->details['claimant_name'] ?? $line->submitted_by_name ?? null;

                return [
                    'id' => $line->id,
                    'ref' => $line->ref,
                    'description' => $line->description,
                    'payee_name' => $line->payee_name ?: $line->payee_supplier_name,
                    'supplier_id' => $fundingMode === 'unpaid_invoice' ? $line->payee_id : null,
                    'funding_mode' => $fundingMode,
                    'claimant_name' => $claimantName,
                    'claimant_user_id' => $line->details['claimant_user_id'] ?? $line->submitted_by_user_id,
                    'claimant_phone' => $line->submitted_by_phone,
                    'job_number' => $line->job_number,
                    'incurred_at' => $line->incurred_at?->toDateString(),
                    'expense_code' => $line->expenseCode?->code,
                    'payable_amount' => $payable,
                    'allocated_amount' => $allocated,
                    'remaining_amount' => $remaining,
                ];
            })
            ->filter(fn (array $line) => bccomp($line['remaining_amount'], '0.00', 2) === 1)
            ->values();

        return response()->json(['status' => 'success', 'data' => $lines]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $voucher = SpendVoucher::with(['paymentSource', 'costLines'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $voucher,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE), 403);

        $period = AccountingPeriod::forDate(now());
        if (! $period || ! $period->isOpen()) {
            return response()->json([
                'message' => $period
                    ? sprintf('The %s %d accounting period is %s. Finance must open a valid period before a voucher can be created.', $period->starts_on->format('F'), $period->year, $period->status)
                    : 'No accounting period covers today. Complete Finance setup before creating a voucher.',
            ], 422);
        }

        $validated = $request->validate([
            // Only the voucher types with complete capture, settlement and GL
            // treatments are public here. Retirements live in the petty-cash
            // requisition workflow; refunds, top-ups and reversals need their
            // own source documents rather than a free-form AP debit.
            'type' => 'required|string|in:advance,payment,reimbursement',
            'payee_name' => 'required|string|max:255',
            'payee_phone' => 'nullable|string|max:32',
            'payee_kra_pin' => 'nullable|string|max:32',
            'total_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'payment_source_id' => 'required_unless:type,retirement|nullable|exists:payment_sources,id',
            'notes' => 'nullable|string',
            'supplier_invoice_no' => 'nullable|string',
            'etims_invoice_no' => 'nullable|string',
            'allocations' => 'required_if:type,payment,reimbursement|array|min:1',
            'allocations.*.cost_line_id' => 'required|integer|distinct|exists:cost_lines,id',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        // The primary key supplies the sequence. count()+1 races under two
        // simultaneous requests and can issue the same voucher number twice.
        $requestedAllocations = collect($validated['allocations'] ?? [])->keyBy('cost_line_id');
        unset($validated['allocations']);

        try {
            $voucher = DB::transaction(function () use ($validated, $period, $requestedAllocations) {
                $liabilities = collect();
                if (in_array($validated['type'], ['payment', 'reimbursement'], true)) {
                    $liabilities = CostLine::query()->withReferenceNames()
                        ->lockForUpdate()->whereKey($requestedAllocations->keys())->get();
                    $beneficiary = $this->assertEligibleLiabilities(
                        $liabilities,
                        $requestedAllocations,
                        $validated['type'],
                    );
                    $validated['payee_name'] = $beneficiary['name'];
                    $validated['supplier_id'] = $beneficiary['supplier_id'];
                    $allocationTotal = $requestedAllocations->reduce(
                        fn (string $total, array $allocation) => bcadd($total, (string) $allocation['amount'], 2),
                        '0.00'
                    );
                    if (bccomp($allocationTotal, (string) $validated['total_amount'], 2) !== 0) {
                        throw new \DomainException("The voucher total must equal its liability allocations ({$allocationTotal}).");
                    }
                }

            $voucher = SpendVoucher::create(array_merge($validated, [
                'voucher_no' => 'PENDING-' . bin2hex(random_bytes(8)),
                // Same word the work queue and procurement use for "awaiting
                // approval". `draft` is reserved for incomplete rows; create is
                // a submission, not a scratch pad.
                'status' => 'pending_approval',
                'transacted_at' => now(),
                'posting_date' => now()->toDateString(),
                // Resolved from the posting date exactly as CostContextResolver
                // does for a cost line. Left null until now, so every voucher
                // journal posted with no period: they could not be included in a
                // period close, and the locked-period guard below had nothing to
                // test against.
                'accounting_period_id' => $period->id,
                'requester_user_id' => auth()->id(),
                'currency' => 'KES',
                'fx_rate' => 1,
                'base_total_amount' => $validated['total_amount'],
                'net_amount' => $validated['total_amount'],
                'net_cash_paid' => $validated['total_amount'],
            ]));

            $voucher->forceFill([
                'voucher_no' => 'SV-' . now()->format('Ymd') . '-' . str_pad((string) $voucher->id, 7, '0', STR_PAD_LEFT),
            ])->save();

                foreach ($requestedAllocations as $allocation) {
                    SpendVoucherAllocation::create([
                        'spend_voucher_id' => $voucher->id,
                        'cost_line_id' => $allocation['cost_line_id'],
                        'amount' => $allocation['amount'],
                    ]);
                }

            return $voucher;
            });
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'spend_voucher_created',
            'model_type' => SpendVoucher::class,
            'model_id' => $voucher->id,
            'message' => "Spend voucher {$voucher->voucher_no} created for {$voucher->payee_name} of KES {$voucher->total_amount}.",
            'ip_address' => request()->ip(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Spend voucher created successfully',
            'data' => $voucher,
        ], 201);
    }

    /**
     * Validate the accounting balance and the human beneficiary together.
     * A balanced journal paid to the wrong person is still a failed payment.
     *
     * @return array{name:string,supplier_id:int|null}
     */
    private function assertEligibleLiabilities($lines, $requestedAllocations, string $voucherType): array
    {
        if ($lines->count() !== $requestedAllocations->count()) {
            throw new \DomainException('One or more selected liabilities no longer exist. Refresh the list and try again.');
        }

        $controlAccounts = ChartOfAccount::postable()->whereIn('code', ChartAccountMap::localMany(['2100', '2150']))->pluck('id');
        $beneficiaryKey = null;
        $beneficiaryName = null;
        $supplierId = null;

        foreach ($lines as $line) {
            $eligible = $line->status === CostLine::STATUS_VERIFIED
                && $line->journal_entry_id !== null
                && DB::table('journal_entries')->where('id', $line->journal_entry_id)->where('status', 'posted')->exists()
                && DB::table('journal_lines')->where('journal_entry_id', $line->journal_entry_id)
                    ->where('entry_type', 'credit')->whereIn('account_id', $controlAccounts)->exists();
            if (! $eligible) {
                throw new \DomainException("Cost line {$line->ref} is not a posted, verified liability.");
            }

            $fundingMode = $line->details['funding_mode'] ?? null;
            $expectedMode = $voucherType === 'payment' ? 'unpaid_invoice' : 'out_of_pocket';
            if ($fundingMode !== null && $fundingMode !== $expectedMode) {
                throw new \DomainException($voucherType === 'payment'
                    ? "Cost line {$line->ref} is not a supplier-credit liability. Use a reimbursement voucher for staff claims."
                    : "Cost line {$line->ref} is not an out-of-pocket staff claim. Use a payment voucher for supplier liabilities.");
            }

            if ($fundingMode === 'unpaid_invoice') {
                $lineSupplierId = (int) $line->payee_id;
                if ($lineSupplierId < 1 || blank($line->payee_supplier_name)) {
                    throw new \DomainException("Cost line {$line->ref} has no supplier-master beneficiary.");
                }
                $lineBeneficiaryKey = 'supplier:'.$lineSupplierId;
                $lineBeneficiaryName = $line->payee_supplier_name;
                $lineSupplier = $lineSupplierId;
            } elseif ($fundingMode === 'out_of_pocket') {
                $claimantId = (int) ($line->details['claimant_user_id'] ?? $line->submitted_by_user_id);
                $lineBeneficiaryKey = 'claimant:'.$claimantId;
                $lineBeneficiaryName = $line->details['claimant_name'] ?? $line->submitted_by_name;
                $lineSupplier = null;
            } else {
                // Historical verified rows predate mandatory funding mode. Keep
                // them payable, but never allow unlike names into one payment.
                $lineBeneficiaryName = $line->payee_name ?: $line->payee_supplier_name
                    ?: $line->submitted_by_name ?: 'Legacy payee';
                $lineBeneficiaryKey = 'legacy:'.mb_strtolower(trim($lineBeneficiaryName));
                $lineSupplier = null;
            }

            if ($beneficiaryKey !== null && $beneficiaryKey !== $lineBeneficiaryKey) {
                throw new \DomainException('One payment voucher can pay only one supplier or claimant. Create separate vouchers for different beneficiaries.');
            }
            $beneficiaryKey = $lineBeneficiaryKey;
            $beneficiaryName = $lineBeneficiaryName;
            $supplierId = $lineSupplier;

            $allocation = (string) $requestedAllocations->get($line->id)['amount'];
            $remaining = bcsub($this->payableAmount($line), $this->activeAllocatedAmount($line->id), 2);
            if (bccomp($allocation, $remaining, 2) === 1) {
                throw new \DomainException("Allocation {$allocation} exceeds the remaining balance {$remaining} on {$line->ref}.");
            }
        }

        return ['name' => (string) $beneficiaryName, 'supplier_id' => $supplierId];
    }

    private function payableAmount(CostLine $line): string
    {
        return bcsub(
            bcadd((string) ($line->net_amount ?? 0), (string) ($line->tax_amount ?? 0), 2),
            (string) ($line->wht_amount ?? 0),
            2
        );
    }

    private function activeAllocatedAmount(int $costLineId): string
    {
        return number_format((float) DB::table('spend_voucher_allocations as sva')
            ->join('spend_vouchers as sv', 'sv.id', '=', 'sva.spend_voucher_id')
            ->where('sva.cost_line_id', $costLineId)
            ->whereNotIn('sv.status', ['rejected', 'reversed'])
            ->sum('sva.amount'), 2, '.', '');
    }

    /** Cancel an unapproved draft and release every liability it reserved. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE), 403);

        $result = DB::transaction(function () use ($request, $id) {
            $voucher = SpendVoucher::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($voucher->status, ['pending_approval', 'draft'], true)) {
                return ['error' => 'Only a voucher awaiting approval can be cancelled.'];
            }
            if ((int) $voucher->requester_user_id !== (int) $request->user()->id) {
                return ['error' => 'Only the person who created this voucher can cancel it.'];
            }

            $costLineIds = SpendVoucherAllocation::where('spend_voucher_id', $voucher->id)->pluck('cost_line_id');
            CostLine::query()->whereKey($costLineIds)->lockForUpdate()->get();
            SpendVoucherAllocation::where('spend_voucher_id', $voucher->id)->delete();
            $voucher->update(['status' => 'rejected']);

            HRAuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'spend_voucher_cancelled',
                'model_type' => SpendVoucher::class,
                'model_id' => $voucher->id,
                'message' => "Spend voucher {$voucher->voucher_no} cancelled; reserved liabilities released.",
                'ip_address' => $request->ip(),
            ]);

            return ['voucher' => $voucher];
        });

        if (isset($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Voucher cancelled.', 'data' => $result['voucher']]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE), 403);

        $result = DB::transaction(function () use ($request, $id) {
            $voucher = SpendVoucher::query()->lockForUpdate()->findOrFail($id);

            if (! in_array($voucher->status, ['pending_approval', 'draft'], true)) {
                return ['error' => 'Only vouchers awaiting approval can be approved'];
            }

            if ($voucher->requester_user_id === $request->user()->id && ! \App\Support\SelfApproval::allowedFor($request->user())) {
                return ['error' => 'You requested this spend voucher, so someone else has to approve it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'];
            }

            $voucher->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            HRAuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'spend_voucher_approved',
                'model_type' => SpendVoucher::class,
                'model_id' => $voucher->id,
                'message' => "Spend voucher {$voucher->voucher_no} approved.",
                'ip_address' => $request->ip(),
            ]);

            return ['voucher' => $voucher];
        });

        if (isset($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Voucher approved successfully',
            'data' => $result['voucher'],
        ]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_POST), 403);

        try {
            $result = DB::transaction(function () use ($request, $id) {
                $voucher = SpendVoucher::query()->lockForUpdate()->findOrFail($id);

                if ($voucher->status !== 'approved' || $voucher->posted_at) {
                    return ['error' => 'Only an approved, unposted voucher can be posted.'];
                }

                $usesSeparationOverride = in_array(
                    $request->user()->id,
                    [$voucher->requester_user_id, $voucher->approved_by],
                    true
                );

                if ($usesSeparationOverride && ! \App\Support\SelfApproval::allowedFor($request->user())) {
                    return ['error' => 'The requester and approver cannot post this voucher.'];
                }

                $period = $voucher->accounting_period_id
                    ? AccountingPeriod::query()->sharedLock()->find($voucher->accounting_period_id)
                    : null;

                if (! $period || ! $period->isOpen()) {
                    return ['error' => $period ? sprintf(
                        'The accounting period %04d-%02d is %s, so this voucher cannot be posted into it.',
                        $period->year,
                        $period->month,
                        $period->status,
                    ) : 'This voucher has no accounting period. Finance must correct its period before posting.'];
                }

                // Cash fact first: Payment (+ float debit when the source is a
                // float). The AP/advance journal below never creates a Payment
                // on its own — that was the disconnect that left bank recon and
                // the petty-cash register blind to voucher settlements.
                $payment = $this->settlementService->settle($voucher, $request->user()->id);

                $voucher->update([
                    'status' => 'posted',
                    'posted_by' => $request->user()->id,
                    'posted_at' => now(),
                ]);

                $entry = $this->journalPostingService->postSpendVoucher($voucher->fresh(['paymentSource', 'allocations']));

                HRAuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'spend_voucher_posted',
                    'model_type' => SpendVoucher::class,
                    'model_id' => $voucher->id,
                    'message' => "Spend voucher {$voucher->voucher_no} posted to General Ledger."
                        . ($payment ? " Payment {$payment->payment_no}." : '')
                        . ($usesSeparationOverride ? ' Separation-of-duties override used.' : ''),
                    'ip_address' => $request->ip(),
                ]);

                return ['voucher' => $voucher, 'journal_entry' => $entry, 'payment' => $payment];
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        if (isset($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Voucher posted to General Ledger successfully',
            'data' => [
                'voucher' => $result['voucher']->fresh(['paymentSource']),
                'journal_entry' => $result['journal_entry'],
                'payment' => $result['payment'],
            ],
        ]);
    }
}
