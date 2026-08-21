<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Resources\JournalEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the general ledger.
 *
 * JournalPostingService has been writing journal entries and lines since the GL
 * plan landed, and nothing has ever been able to read them back: no controller,
 * no route, no screen. The consequences were not cosmetic. There was no trial
 * balance, so nothing could demonstrate the ledger balanced; the cost
 * verification screen printed `journal_entry_no` as inert text with no way to
 * see the legs behind it; and a reversal left no reviewable trace, because the
 * compensating entry was as unreadable as the entry it reversed.
 *
 * Read-only by design. Journals are written by the posting service as a
 * consequence of verifying a cost or posting a voucher — never by hand — so
 * exposing create/update here would introduce a second, unreconciled way to
 * move the ledger. That is the precise mistake the petty cash board-request
 * path made.
 */
class JournalEntryController extends Controller
{
    /**
     * What this ledger does and does not contain, returned with every summary.
     *
     * WNG's statutory accounts live in an external package. This ledger holds
     * the cost side only — it has no revenue, no payroll, no opening balances
     * and no equity movement, so its account summary is not a trial balance in
     * the accounting sense and must never be read as one. It cannot be
     * unbalanced either: every entry is constructed balanced, so `is_balanced`
     * proves the posting code works and says nothing whatever about the
     * business.
     *
     * Shipped in the payload rather than written on the screen, so the caveat
     * travels with the numbers into whatever consumes them.
     */
    private const COVERAGE = [
        'is_statutory_trial_balance' => false,
        'includes' => [
            'Verified project and overhead costs',
            'Recoverable input VAT and withholding tax',
            'Stores inventory movements and goods-received accruals',
        ],
        'excludes' => [
            'Revenue, client invoices and receipts',
            'Payroll',
            'Bank and cash movements not raised as a spend voucher',
            'Opening balances, equity, depreciation and year-end adjustments',
        ],
        'note' => 'A cost-side account summary, not a statutory trial balance. Every entry is '
            . 'constructed balanced, so a balanced total confirms the posting logic, not the books. '
            . 'The statutory position is prepared in WNG\'s external accounting package.',
    ];

    /** Sources a caller may filter by, mapped to the stored FQCNs. */
    private const SOURCES = [
        'cost_line' => CostLine::class,
        'spend_voucher' => SpendVoucher::class,
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'source' => ['nullable', 'string', 'in:cost_line,spend_voucher'],
            'status' => ['nullable', 'string', 'in:draft,posted,reversed'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'project_enquiry_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // accountingPeriod is eager-loaded because the resource exposes it and
        // the list is where "which period did this land in" is actually asked.
        $query = JournalEntry::with('accountingPeriod')
            ->orderByDesc('posting_date')
            ->orderByDesc('id');

        if ($source = $filters['source'] ?? null) {
            $query->where('source_type', self::SOURCES[$source]);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($from = $filters['from'] ?? null) {
            $query->whereDate('posting_date', '>=', $from);
        }

        if ($to = $filters['to'] ?? null) {
            $query->whereDate('posting_date', '<=', $to);
        }

        // Account and project live on the lines, so both narrow the entry list
        // through an existence check rather than a join — a join would multiply
        // an entry by its matching legs and paginate the duplicates.
        if ($accountId = $filters['account_id'] ?? null) {
            $query->whereHas('lines', fn ($q) => $q->where('account_id', $accountId));
        }

        if ($enquiryId = $filters['project_enquiry_id'] ?? null) {
            $query->whereHas('lines', fn ($q) => $q->where('project_enquiry_id', $enquiryId));
        }

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                    ->orWhere('source_ref', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $entries = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'status' => 'success',
            'data' => JournalEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    /** One entry with every leg, the account each hit, and its reversal links. */
    public function show(Request $request, JournalEntry $journal): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $journal->load(['lines.account', 'accountingPeriod', 'reversedBy']);

        return response()->json([
            'status' => 'success',
            'data' => new JournalEntryResource($journal),
        ]);
    }

    /**
     * Debits and credits by account for a period — a cost-side account summary.
     *
     * Named `trialBalance` for its route, but see COVERAGE: it is not one, and
     * the payload says so. With no revenue, payroll or opening balances in this
     * ledger, the totals cannot describe a financial position; what they are
     * genuinely good for is the detail behind a single account — how much hit
     * Input VAT Recoverable in March, what accumulated in WHT Payable — which is
     * the number Finance carries to the return and to the external package.
     *
     * `is_balanced` is retained as an integrity check on the posting code, not
     * as an accounting assertion. Every entry builds its credit as a balancing
     * figure, so a false here means a bug, while a true means only that no bug
     * fired.
     *
     * Draft and reversed entries are excluded: this states what has been posted
     * and still stands.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('journal_entries.posting_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('journal_entries.posting_date', '<=', $to))
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.category')
            ->orderBy('chart_of_accounts.code')
            ->get([
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'debit' THEN journal_lines.base_amount ELSE 0 END) as debit"),
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'credit' THEN journal_lines.base_amount ELSE 0 END) as credit"),
            ]);

        $accounts = $rows->map(fn ($row) => [
            'account_id' => (int) $row->account_id,
            'code' => $row->code,
            'name' => $row->name,
            'category' => $row->category,
            'debit' => number_format((float) $row->debit, 2, '.', ''),
            'credit' => number_format((float) $row->credit, 2, '.', ''),
            // Signed toward the side the account sits on, so a reader does not
            // have to hold each account's normal balance in their head.
            'balance' => number_format((float) $row->debit - (float) $row->credit, 2, '.', ''),
        ]);

        $totalDebit = $accounts->sum(fn (array $row) => (float) $row['debit']);
        $totalCredit = $accounts->sum(fn (array $row) => (float) $row['credit']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'accounts' => $accounts,
                'totals' => [
                    'debit' => number_format($totalDebit, 2, '.', ''),
                    'credit' => number_format($totalCredit, 2, '.', ''),
                    'difference' => number_format($totalDebit - $totalCredit, 2, '.', ''),
                    'is_balanced' => bccomp(
                        number_format($totalDebit, 2, '.', ''),
                        number_format($totalCredit, 2, '.', ''),
                        2,
                    ) === 0,
                ],
                'coverage' => self::COVERAGE,
            ],
            'filters' => $filters,
        ]);
    }
}
