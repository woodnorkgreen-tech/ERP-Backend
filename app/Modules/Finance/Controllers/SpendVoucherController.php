<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\SpendVoucher;
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

        $query = SpendVoucher::with('paymentSource')
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
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $sources = PaymentSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'currency']);

        return response()->json([
            'status' => 'success',
            'data' => $sources,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_READ), 403);

        $voucher = SpendVoucher::with('paymentSource')->findOrFail($id);

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
            'payment_source_id' => 'nullable|exists:payment_sources,id',
            'notes' => 'nullable|string',
            'supplier_invoice_no' => 'nullable|string',
            'etims_invoice_no' => 'nullable|string',
        ]);

        // The primary key supplies the sequence. count()+1 races under two
        // simultaneous requests and can issue the same voucher number twice.
        $voucher = DB::transaction(function () use ($validated, $period) {
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

            return $voucher;
        });

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

    public function approve(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_APPROVE), 403);

        $voucher = SpendVoucher::findOrFail($id);

        if ($voucher->status !== 'draft') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only draft vouchers can be approved',
            ], 422);
        }

        if ($voucher->requester_user_id === $request->user()->id && ! \App\Support\SelfApproval::allowedFor($request->user())) {
            return response()->json([
                'status' => 'error',
                'message' => 'You requested this spend voucher, so someone else has to approve it. If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.',
            ], 422);
        }

        $voucher->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'spend_voucher_approved',
            'model_type' => SpendVoucher::class,
            'model_id' => $voucher->id,
            'message' => "Spend voucher {$voucher->voucher_no} approved.",
            'ip_address' => request()->ip(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Voucher approved successfully',
            'data' => $voucher,
        ]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_POST), 403);

        $voucher = SpendVoucher::findOrFail($id);

        if ($voucher->status !== 'approved' || $voucher->posted_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only an approved, unposted voucher can be posted.',
            ], 422);
        }

        if (in_array($request->user()->id, [$voucher->requester_user_id, $voucher->approved_by], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The requester and approver cannot post this voucher.',
            ], 422);
        }

        // The same guard CostVerificationService applies before posting a cost.
        // A closed period that still accepts vouchers is not closed, and the
        // asymmetry was invisible while vouchers carried no period at all.
        $period = $voucher->accounting_period_id
            ? AccountingPeriod::find($voucher->accounting_period_id)
            : null;

        if (! $period || ! $period->isOpen()) {
            return response()->json([
                'status' => 'error',
                'message' => $period ? sprintf(
                    'The accounting period %04d-%02d is %s, so this voucher cannot be posted into it.',
                    $period->year,
                    $period->month,
                    $period->status,
                ) : 'This voucher has no accounting period. Finance must correct its period before posting.',
            ], 422);
        }

        $journalEntry = DB::transaction(function () use ($voucher) {
            $voucher->update([
                'status' => 'posted',
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            $entry = $this->journalPostingService->postSpendVoucher($voucher);

            HRAuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'spend_voucher_posted',
                'model_type' => SpendVoucher::class,
                'model_id' => $voucher->id,
                'message' => "Spend voucher {$voucher->voucher_no} posted to General Ledger.",
                'ip_address' => request()->ip(),
            ]);

            return $entry;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Voucher posted to General Ledger successfully',
            'data' => [
                'voucher' => $voucher->fresh(),
                'journal_entry' => $journalEntry,
            ],
        ]);
    }
}
