<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashCashCount;
use App\Modules\Finance\PettyCash\Models\PettyCashCustodyHandover;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\Services\FinanceAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wave 3 petty-cash controls: physical custody (W5-3), cash counts (W5-7) and
 * surrender ageing (W5-8). None of these ever writes to the float ledger or
 * the general ledger — a count variance is recorded and explained, and its
 * accounting treatment stays an open Finance/accountant decision.
 */
class PettyCashControlController extends Controller
{
    /** W5-3: who physically holds the float now, and how custody got there. */
    public function custody(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_VIEW_BALANCE)
            || $request->user()?->can(Permissions::FINANCE_PETTY_CASH_CUSTODY), 403);

        $balance = PettyCashBalance::current();
        $handovers = PettyCashCustodyHandover::query()->latest('id')->limit(20)->get();
        $names = User::query()->whereIn('id', $handovers->flatMap(fn ($h) => [
            $h->outgoing_custodian_user_id, $h->incoming_custodian_user_id, $h->recorded_by, $h->confirmed_by,
        ])->push($balance->held_by)->filter()->unique())->pluck('name', 'id');

        return response()->json(['success' => true, 'data' => [
            'held_by' => $balance->held_by ? ['id' => $balance->held_by, 'name' => $names[$balance->held_by] ?? null] : null,
            'system_balance' => (string) $balance->current_balance,
            'handovers' => $handovers->map(fn ($h) => [
                ...$h->toArray(),
                'outgoing_custodian_name' => $names[$h->outgoing_custodian_user_id] ?? null,
                'incoming_custodian_name' => $names[$h->incoming_custodian_user_id] ?? null,
                'recorded_by_name' => $names[$h->recorded_by] ?? null,
                'confirmed_by_name' => $names[$h->confirmed_by] ?? null,
            ]),
            // Only a custody manager picks the next holder, so only they get the list.
            'eligible_custodians' => $request->user()->can(Permissions::FINANCE_PETTY_CASH_CUSTODY)
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]]);
    }

    public function handover(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_CUSTODY), 403);
        $data = $request->validate(['incoming_custodian_user_id' => ['required', 'integer', 'exists:users,id'],
            'physical_cash' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $handover = DB::transaction(function () use ($request, $data) {
            $balance = PettyCashBalance::query()->whereKey(PettyCashBalance::current()->id)->lockForUpdate()->firstOrFail();
            if ((int) $balance->held_by === (int) $data['incoming_custodian_user_id']) {
                throw ValidationException::withMessages(['incoming_custodian_user_id' => 'This person already holds the float.']);
            }
            $variance = isset($data['physical_cash']) ? bcsub((string) $data['physical_cash'], (string) $balance->current_balance, 2) : null;
            $row = PettyCashCustodyHandover::create(['petty_cash_balance_id' => $balance->id,
                'outgoing_custodian_user_id' => $balance->held_by, 'incoming_custodian_user_id' => $data['incoming_custodian_user_id'],
                'system_balance' => $balance->current_balance, 'physical_cash' => $data['physical_cash'] ?? null,
                'variance' => $variance, 'recorded_by' => $request->user()->id, 'notes' => $data['notes'] ?? null]);
            // Custody moves; no transaction's created_by is touched.
            $balance->update(['held_by' => $data['incoming_custodian_user_id']]);
            return $row;
        });
        return response()->json(['success' => true, 'message' => 'Physical custody handed over; transaction history was not rewritten.', 'data' => $handover], 201);
    }

    /** The incoming custodian acknowledges they received the float. */
    public function confirmHandover(Request $request, int $id): JsonResponse
    {
        $handover = DB::transaction(function () use ($request, $id) {
            $handover = PettyCashCustodyHandover::query()->lockForUpdate()->findOrFail($id);
            if ((int) $handover->incoming_custodian_user_id !== (int) $request->user()?->id) {
                throw ValidationException::withMessages(['handover' => 'Only the incoming custodian can confirm receiving the float.']);
            }
            if ($handover->confirmed_at) {
                throw ValidationException::withMessages(['handover' => 'This handover is already confirmed.']);
            }
            $handover->update(['confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
            return $handover;
        });
        return response()->json(['success' => true, 'message' => 'Handover confirmed.', 'data' => $handover]);
    }

    public function cashCounts(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS)
            || $request->user()?->can(Permissions::FINANCE_PETTY_CASH_CUSTODY), 403);
        return response()->json(['success' => true, 'data' => PettyCashCashCount::with('attachments')->latest('id')->paginate(25)]);
    }

    /**
     * W5-7: record a physical count against the system float.
     *
     * Variance = Physical Cash − ERP Float Balance (the register's definition).
     * `supported_adjustments` is cash legitimately out of the tin but not yet
     * in the system; whatever remains unexplained must carry an explanation.
     * The components snapshot says what the expected figure is made of, so a
     * reviewer can tell a posting backlog from a shortage.
     */
    public function storeCashCount(Request $request, FinanceAttachmentService $attachments): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_CUSTODY), 403);
        $data = $request->validate(['physical_cash' => ['required', 'numeric', 'min:0'],
            'supported_adjustments' => ['nullable', 'numeric', 'min:0'], 'explanation' => ['nullable', 'string', 'max:2000'],
            'corrective_action_reference' => ['nullable', 'string', 'max:255'], 'evidence' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp']]);

        $count = DB::transaction(function () use ($request, $data) {
            $balance = PettyCashBalance::query()->whereKey(PettyCashBalance::current()->id)->lockForUpdate()->firstOrFail();
            $system = number_format((float) $balance->current_balance, 2, '.', '');
            $physical = number_format((float) $data['physical_cash'], 2, '.', '');
            $adjustments = number_format((float) ($data['supported_adjustments'] ?? 0), 2, '.', '');
            $variance = bcsub($physical, $system, 2);
            $unexplained = bcsub(bcadd($physical, $adjustments, 2), $system, 2);

            if (bccomp($unexplained, '0.00', 2) !== 0 && blank($data['explanation'] ?? null)) {
                throw ValidationException::withMessages(['explanation' => "The count differs from the system float by KES {$unexplained} after supported adjustments. Explain the difference."]);
            }

            $components = $this->floatComponents($system);
            $components['unexplained_variance'] = $unexplained;

            return PettyCashCashCount::create(['petty_cash_balance_id' => $balance->id, 'system_balance' => $system,
                'physical_cash' => $physical, 'outstanding_advances' => $components['outstanding_advances']['amount'],
                'supported_adjustments' => $adjustments, 'variance' => $variance, 'components' => $components,
                'counted_by' => $request->user()->id, 'custodian_user_id' => $balance->held_by,
                'explanation' => $data['explanation'] ?? null, 'corrective_action_reference' => $data['corrective_action_reference'] ?? null]);
        });
        if ($request->hasFile('evidence')) {
            $attachments->attachFile(PettyCashCashCount::class, $count->id, $request->file('evidence'), $request->user()->id, 'cash_count_evidence');
        }
        return response()->json(['success' => true, 'message' => 'Cash count recorded. The variance did not alter the ledger.', 'data' => $count->fresh('attachments')], 201);
    }

    public function reviewCashCount(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_REVIEW_CASH_COUNT), 403);
        $count = DB::transaction(function () use ($request, $id) {
            $count = PettyCashCashCount::query()->lockForUpdate()->findOrFail($id);
            $me = (int) $request->user()->id;
            if ($me === (int) $count->counted_by || ($count->custodian_user_id && $me === (int) $count->custodian_user_id)) {
                throw ValidationException::withMessages(['review' => 'The reviewer must be independent of the person who counted and of the custodian.']);
            }
            if ($count->reviewed_at) {
                throw ValidationException::withMessages(['review' => 'This cash count has already been reviewed.']);
            }
            $count->update(['reviewed_by' => $me, 'reviewed_at' => now()]);
            return $count;
        });
        return response()->json(['success' => true, 'message' => 'Cash count reviewed.', 'data' => $count]);
    }

    /** W5-8: every disbursed, unreconciled advance, with its surrender ageing. */
    public function outstandingAdvances(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS), 403);

        $dueSoonDays = PettyCashRequisition::dueSoonDays();
        $today = now()->startOfDay();
        $rows = PettyCashRequisition::query()
            ->with(['requester:id,name'])
            // Report 75R-B: only money actually out and unaccounted is an outstanding
            // advance, and a requisition paid in several transfers dates from the first.
            ->outstandingAdvances()
            ->withMin(['disbursements as first_paid_on' => fn ($q) => $q->where('status', 'active')], 'date_disbursed')
            ->orderByRaw('surrender_due_at IS NULL, surrender_due_at')
            ->get()
            ->map(function (PettyCashRequisition $advance) use ($dueSoonDays, $today) {
                $paidOn = $advance->first_paid_on;
                return [
                    'id' => $advance->id,
                    'requisition_number' => $advance->requisition_number,
                    'requester' => $advance->requester?->name,
                    'purpose' => $advance->purpose,
                    'amount' => number_format((float) $advance->advance_exposure, 2, '.', ''),
                    'approved_amount' => (string) $advance->total_amount,
                    'status' => $advance->status,
                    'disbursed_on' => $paidOn ? \Carbon\Carbon::parse($paidOn)->toDateString() : null,
                    'days_outstanding' => $paidOn ? (int) \Carbon\Carbon::parse($paidOn)->startOfDay()->diffInDays($today) : null,
                    'surrender_due_at' => $advance->surrender_due_at?->toDateString(),
                    'state' => $advance->surrenderState($dueSoonDays, $today),
                ];
            });

        return response()->json(['success' => true, 'data' => [
            'advances' => $rows,
            'summary' => [
                'count' => $rows->count(),
                'amount' => number_format((float) $rows->sum('amount'), 2, '.', ''),
                'by_state' => $rows->countBy('state'),
            ],
            'policy' => [
                'surrender_due_days' => \App\Modules\Finance\Models\FinanceSetting::approvedValue('petty_cash_surrender_due_days'),
                'due_soon_days' => $dueSoonDays,
            ],
        ]]);
    }

    /**
     * What the system float is made of right now, and what might explain a
     * difference — kept separate so nothing is netted into one number.
     *
     * @return array<string, mixed>
     */
    private function floatComponents(string $systemBalance): array
    {
        $lastCount = PettyCashCashCount::query()->latest('id')->value('created_at');
        $movements = DB::table('petty_cash_ledger_entries')
            ->when($lastCount, fn ($q) => $q->where('posted_at', '>', $lastCount))
            ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount END),0) credits, COALESCE(SUM(CASE WHEN type='debit' THEN amount END),0) debits")
            ->first();

        $outstanding = PettyCashRequisition::query()->outstandingAdvances()->get();

        $floatAccountIds = PaymentSource::query()->where('type', 'petty_cash')->whereNotNull('gl_account_id')->pluck('gl_account_id');
        $glFloat = $floatAccountIds->isEmpty() ? null : number_format((float) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $floatAccountIds)->where('e.status', '!=', 'draft')
            ->selectRaw("SUM(CASE WHEN l.entry_type='debit' THEN l.amount ELSE -l.amount END) balance")
            ->value('balance'), 2, '.', '');

        return [
            'system_float_balance' => $systemBalance,
            'gl_float_balance' => $glFloat,
            'outstanding_advances' => [
                'count' => $outstanding->count(),
                'amount' => number_format((float) $outstanding->sum(fn ($advance) => (float) $advance->advance_exposure), 2, '.', ''),
            ],
            'since_last_count' => [
                'from' => $lastCount?->toIso8601String(),
                'float_credits' => number_format((float) $movements->credits, 2, '.', ''),
                'float_debits' => number_format((float) $movements->debits, 2, '.', ''),
            ],
            'unresolved_gl_failures' => [
                'advance_postings' => PettyCashRequisition::query()->whereNotNull('advance_gl_posting_failed_at')->count(),
                'cost_postings' => Payment::query()->whereNotNull('cost_gl_posting_failed_at')->count(),
            ],
        ];
    }
}
