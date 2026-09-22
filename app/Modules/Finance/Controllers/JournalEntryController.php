<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\SpendVoucher;
use App\Modules\Finance\Resources\JournalEntryResource;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\LedgerExportService;
use App\Modules\Finance\Support\LedgerCoverage;
use App\Modules\ProcurementStores\Models\BillPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $query = JournalEntry::with(['accountingPeriod', 'costLine.projectEnquiry'])
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

        $journal->load([
            'lines.account',
            'accountingPeriod',
            'reversedBy',
            'costLine.projectEnquiry',
            'costLine.expenseCode',
            'costLine.vatTreatment',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => new JournalEntryResource($journal),
        ]);
    }

    /**
     * Correct a posted entry by posting its opposite.
     *
     * The one write on this controller, and it is deliberately not an exception
     * to the read-only rule above. That rule exists to stop a SECOND way of
     * moving the ledger appearing beside the posting service; this endpoint
     * moves nothing itself — it asks `JournalPostingService` for the same
     * compensating entry it has always written for cost lines, so there is
     * still exactly one writer.
     *
     * Why it has to exist: until now only a cost line could be reversed. A
     * mis-posted supplier invoice, supplier payment, payroll run or spend
     * voucher could be corrected only by editing the database by hand — which
     * is both the least auditable action available and the one an immutable
     * ledger is supposed to make unnecessary.
     *
     * A reason is required and not optional-with-a-default. The reversal is
     * permanent and public; the person reading it in six months needs to know
     * why it happened, and a default would guarantee that most of them say
     * nothing.
     */
    public function reverse(Request $request, JournalEntry $journal, JournalPostingService $posting): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_JOURNALS_REVERSE), 403);

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $belongsToPayment = ($journal->source_type === Payment::class
                && Payment::query()->whereKey($journal->source_id)->exists())
            || ($journal->source_type === BillPayment::class
                && BillPayment::query()->whereKey($journal->source_id)->whereNotNull('disbursement_id')->exists())
            || ($journal->spend_voucher_id
                && Payment::query()->where('spend_voucher_id', $journal->spend_voucher_id)->exists());

        if ($belongsToPayment) {
            return response()->json([
                'message' => 'This journal belongs to a Payment. Reverse the Payment so its liability, voucher, cashbook and audit trail are corrected atomically.',
            ], 422);
        }

        try {
            $reversal = $posting->reverseEntry($journal, $request->user()->id, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            // The service's refusals are all business rules a person can act on
            // — already reversed, never posted, no open month to post into — so
            // they belong in front of the user rather than in a 500.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $reversal->load(['lines.account', 'accountingPeriod', 'reversalOf']);

        return response()->json([
            'status' => 'success',
            'message' => 'Entry ' . $journal->entry_no . ' reversed by ' . $reversal->entry_no . '.',
            'data' => new JournalEntryResource($reversal),
        ]);
    }

    /**
     * Debits and credits by account for a period — a cost-and-revenue account
     * summary.
     *
     * Named `trialBalance` for its route, but see LedgerCoverage: it is not a
     * statutory one yet, and the payload says so. Depreciation and opening
     * balances are still missing, so the totals cannot describe a full
     * financial position; what they are genuinely good for is the detail
     * behind a single account — how much hit Input VAT Recoverable in March,
     * what accumulated in WHT Payable — which is the number Finance carries to
     * the return and to the external package.
     *
     * `is_balanced` is retained as an integrity check on the posting code, not
     * as an accounting assertion. Every entry builds its credit as a balancing
     * figure, so a false here means a bug, while a true means only that no bug
     * fired.
     *
     * Draft entries are excluded. Reversed originals remain alongside their
     * compensating entries: removing an original rewrites its historical month
     * and leaves the reversal as an unsupported negative cost.
     *
     * `account_type` rides along additively (Profit and Loss sub-grouping —
     * direct cost, overhead, opex, revenue — alongside the existing `category`
     * which is the asset/liability/equity/revenue/expense split) so a caller
     * can subtotal without a second query. It is not always populated — see
     * ProfitAndLossService for how an unclassified account is handled rather
     * than silently dropped.
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
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('journal_entries.posting_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('journal_entries.posting_date', '<=', $to))
            ->groupBy(
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                'chart_of_accounts.account_type',
            )
            ->orderBy('chart_of_accounts.code')
            ->get([
                'chart_of_accounts.id as account_id',
                'chart_of_accounts.code',
                'chart_of_accounts.name',
                'chart_of_accounts.category',
                'chart_of_accounts.account_type',
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'debit' THEN journal_lines.base_amount ELSE 0 END) as debit"),
                DB::raw("SUM(CASE WHEN journal_lines.entry_type = 'credit' THEN journal_lines.base_amount ELSE 0 END) as credit"),
            ]);

        $accounts = $rows->map(fn ($row) => [
            'account_id' => (int) $row->account_id,
            'code' => $row->code,
            'name' => $row->name,
            'category' => $row->category,
            'account_type' => $row->account_type,
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
                'coverage' => LedgerCoverage::describe(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * The chronological book for one ledger account.
     *
     * A trial balance proves the totals agree, but it cannot answer which
     * documents make up an account balance.  This statement carries the
     * balance brought forward, every debit and credit in posting order, and a
     * running balance. Reversed originals remain visible beside their
     * compensating entries: an account book is an audit record, not a view of
     * only the transactions that survived correction.
     */
    public function accountStatement(Request $request, ChartOfAccount $account): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $signedAmount = "CASE WHEN journal_lines.entry_type = 'debit' "
            . "THEN journal_lines.base_amount ELSE -journal_lines.base_amount END";

        $openingBalance = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('journal_entries.posting_date', '<', $from))
            // With no lower boundary there is, by definition, no brought-forward balance.
            ->when(! ($filters['from'] ?? null), fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw("COALESCE(SUM({$signedAmount}), 0) as balance")
            ->value('balance');

        $query = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('journal_entries.posting_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('journal_entries.posting_date', '<=', $to))
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->select([
                'journal_lines.id',
                'journal_lines.journal_entry_id',
                'journal_lines.entry_type',
                'journal_lines.base_amount',
                'journal_lines.description',
                'journal_entries.entry_no',
                'journal_entries.posting_date',
                'journal_entries.source_ref',
                'journal_entries.status',
            ]);

        $periodMovement = (float) (clone $query)
            ->reorder()
            ->select([])
            ->selectRaw("COALESCE(SUM({$signedAmount}), 0) as movement")
            ->value('movement');

        $perPage = $filters['per_page'] ?? 50;
        $page = $query->paginate($perPage);

        // Seed this page with all earlier movements in the selected period so
        // the running balance stays correct on page two and beyond.
        $priorMovement = 0.0;
        if ($page->currentPage() > 1) {
            $priorMovement = (float) (clone $query)
                ->limit(($page->currentPage() - 1) * $perPage)
                ->get(['journal_lines.entry_type', 'journal_lines.base_amount'])
                ->sum(fn ($line) => $line->entry_type === 'debit'
                    ? (float) $line->base_amount
                    : - (float) $line->base_amount);
        }

        $running = (float) $openingBalance + $priorMovement;
        $rows = collect($page->items())->map(function ($line) use (&$running) {
            $debit = $line->entry_type === 'debit' ? (float) $line->base_amount : 0.0;
            $credit = $line->entry_type === 'credit' ? (float) $line->base_amount : 0.0;
            $running += $debit - $credit;

            return [
                'id' => (int) $line->id,
                'journal_entry_id' => (int) $line->journal_entry_id,
                'entry_no' => $line->entry_no,
                'posting_date' => $line->posting_date,
                'source_ref' => $line->source_ref,
                'description' => $line->description,
                'status' => $line->status,
                'debit' => number_format($debit, 2, '.', ''),
                'credit' => number_format($credit, 2, '.', ''),
                'balance' => number_format($running, 2, '.', ''),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'category' => $account->category,
                    'normal_balance' => $account->normal_balance,
                ],
                'opening_balance' => number_format((float) $openingBalance, 2, '.', ''),
                'rows' => $rows,
                // This is the selected period's actual closing balance, not
                // merely the last row of the current page.
                'closing_balance' => number_format((float) $openingBalance + $periodMovement, 2, '.', ''),
                'balance_basis' => 'debit_less_credit',
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Document-batched journals for the external accounting package.
     *
     * CSV by default because that is what gets keyed or imported; JSON when the
     * caller wants to render it. Both come off one service call, so the file and
     * the screen can never disagree.
     */
    public function export(Request $request, LedgerExportService $exporter): JsonResponse|StreamedResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $exporter->documentJournals($filters['from'], $filters['to']);

        if (($filters['format'] ?? 'csv') === 'json') {
            return response()->json(['status' => 'success', 'data' => $data]);
        }

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Journal no', 'Date', 'Account code', 'Account name', 'Description',
                'Debit', 'Credit', 'Jobs']);

            foreach ($data['documents'] as $document) {
                foreach ($document['rows'] as $row) {
                    fputcsv($out, [
                        $document['journal_no'],
                        $document['posting_date'],
                        $row['account_code'],
                        $row['account_name'],
                        $document['description'],
                        $row['debit'],
                        $row['credit'],
                        implode(' ', $document['job_numbers']),
                    ]);
                }
            }

            fclose($out);
        }, "cost-journals-{$filters['from']}-to-{$filters['to']}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
