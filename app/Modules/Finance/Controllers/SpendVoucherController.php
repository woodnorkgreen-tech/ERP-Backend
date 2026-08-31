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
use App\Modules\HR\Models\HRAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpendVoucherController extends Controller
{
    public function __construct(private JournalPostingService $journalPostingService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $query = SpendVoucher::with('paymentSource')->withCount('costLines')
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
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

        return [
            'total' => (int) $counts->sum('count'),
            'draft' => (int) ($counts['draft']->count ?? 0),
            'approved' => (int) ($counts['approved']->count ?? 0),
            'posted' => (int) ($counts['posted']->count ?? 0),
            'posted_amount' => number_format((float) ($counts['posted']->amount ?? 0), 2, '.', ''),
        ];
    }

    /**
     * Active payment sources for the voucher form.
     *
     * `payment_source_id` decides which GL account the credit leg hits
     * (resolveAccountsForVoucher reads its gl_account_id), yet the only list of
     * sources anywhere was on the receivables endpoint, gated on a receivables
     * permission a voucher creator has no reason to hold. So the form omitted
     * the field, and every voucher fell back to a chart lookup for any asset
     * account it could find.
     */
    public function paymentSources(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ)
                || $request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE),
            403
        );

        $sources = PaymentSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'currency']);

        return response()->json([
            'status' => 'success',
            'data' => $sources,
        ]);
    }

    /** Verified, journalised liabilities which have not already been paid. */
    public function eligibleLiabilities(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_CREATE), 403);

        $controlAccounts = ChartOfAccount::postable()->whereIn('code', ['2100', '2150'])->pluck('id');
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

                return [
                    'id' => $line->id,
                    'ref' => $line->ref,
                    'description' => $line->description,
                    'payee_name' => $line->payee_name ?: $line->payee_supplier_name,
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
            'type' => 'required|string|in:advance,payment,retirement,reimbursement',
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
                    $liabilities = CostLine::query()->lockForUpdate()->whereKey($requestedAllocations->keys())->get();
                    $this->assertEligibleLiabilities($liabilities, $requestedAllocations);
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
                'status' => 'draft',
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

    private function assertEligibleLiabilities($lines, $requestedAllocations): void
    {
        if ($lines->count() !== $requestedAllocations->count()) {
            throw new \DomainException('One or more selected liabilities no longer exist. Refresh the list and try again.');
        }

        $controlAccounts = ChartOfAccount::postable()->whereIn('code', ['2100', '2150'])->pluck('id');
        foreach ($lines as $line) {
            $eligible = $line->status === CostLine::STATUS_VERIFIED
                && $line->journal_entry_id !== null
                && DB::table('journal_entries')->where('id', $line->journal_entry_id)->where('status', 'posted')->exists()
                && DB::table('journal_lines')->where('journal_entry_id', $line->journal_entry_id)
                    ->where('entry_type', 'credit')->whereIn('account_id', $controlAccounts)->exists();
            if (! $eligible) {
                throw new \DomainException("Cost line {$line->ref} is not a posted, verified liability.");
            }

            $allocation = (string) $requestedAllocations->get($line->id)['amount'];
            $remaining = bcsub($this->payableAmount($line), $this->activeAllocatedAmount($line->id), 2);
            if (bccomp($allocation, $remaining, 2) === 1) {
                throw new \DomainException("Allocation {$allocation} exceeds the remaining balance {$remaining} on {$line->ref}.");
            }
        }
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
            if ($voucher->status !== 'draft') {
                return ['error' => 'Only a draft voucher can be cancelled.'];
            }
            if ((int) $voucher->requester_user_id !== (int) $request->user()->id) {
                return ['error' => 'Only the person who created this draft can cancel it.'];
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
                'message' => "Draft spend voucher {$voucher->voucher_no} cancelled; reserved liabilities released.",
                'ip_address' => $request->ip(),
            ]);

            return ['voucher' => $voucher];
        });

        if (isset($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        return response()->json(['status' => 'success', 'message' => 'Draft voucher cancelled.', 'data' => $result['voucher']]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE), 403);

        $result = DB::transaction(function () use ($request, $id) {
            $voucher = SpendVoucher::query()->lockForUpdate()->findOrFail($id);

            if ($voucher->status !== 'draft') {
                return ['error' => 'Only draft vouchers can be approved'];
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

            $voucher->update([
                'status' => 'posted',
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            $entry = $this->journalPostingService->postSpendVoucher($voucher);

            HRAuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'spend_voucher_posted',
                'model_type' => SpendVoucher::class,
                'model_id' => $voucher->id,
                'message' => "Spend voucher {$voucher->voucher_no} posted to General Ledger."
                    . ($usesSeparationOverride ? ' Separation-of-duties override used.' : ''),
                'ip_address' => $request->ip(),
            ]);

            return ['voucher' => $voucher, 'journal_entry' => $entry];
        });

        if (isset($result['error'])) {
            return response()->json(['status' => 'error', 'message' => $result['error']], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Voucher posted to General Ledger successfully',
            'data' => [
                'voucher' => $result['voucher']->fresh(),
                'journal_entry' => $result['journal_entry'],
            ],
        ]);
    }
}
