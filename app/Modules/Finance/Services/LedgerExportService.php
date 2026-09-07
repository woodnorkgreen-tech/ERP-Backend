<?php

namespace App\Modules\Finance\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The hand-off to WNG's statutory books.
 *
 * WNG's P&L and balance sheet are prepared in an external accounting package.
 * This system is the subledger: it knows, transaction by transaction, what a
 * job cost and what tax rode on it. What the external package needs from it is
 * not that detail — it needs periodic journals that agree with it.
 *
 * So the export batches. Internally every cost line posts its own journal
 * entry, which is right for traceability: each line reverses independently and
 * each carries its own source document. But a bookkeeper keying a delivery of
 * twenty materials does not want twenty journals; they want one journal for the
 * delivery note in their hand. This collapses the ledger to **one journal per
 * source document**, with one row per account within it, and it stays reconciled
 * because the collapse is a pure sum over the same posted lines.
 *
 * The batching is therefore a reporting concern, resolved here, rather than a
 * change to how the ledger is written. That was the cheaper of the two designs
 * and it is also the safer one: nothing about drill-back, reversal or audit
 * changes to gain it.
 */
class LedgerExportService
{
    /**
     * Document-batched journals for a date range.
     *
     * @return array<string, mixed>
     */
    public function documentJournals(string $from, string $to): array
    {
        $lines = $this->postedLines($from, $to);

        $documents = $lines
            ->groupBy(fn (object $line) => $line->document_ref . '|' . $line->posting_date)
            ->map(fn (Collection $group) => $this->document($group))
            ->sortBy([['posting_date', 'asc'], ['document_ref', 'asc']])
            ->values();

        $debit = $documents->reduce(fn (string $c, array $d) => bcadd($c, $d['total_debit'], 2), '0.00');
        $credit = $documents->reduce(fn (string $c, array $d) => bcadd($c, $d['total_credit'], 2), '0.00');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'documents' => $documents->all(),
            'totals' => [
                'document_count' => $documents->count(),
                // How much collapsing actually happened — the number that says
                // whether this export is worth having.
                'source_entry_count' => $lines->pluck('journal_entry_id')->unique()->count(),
                'total_debit' => $debit,
                'total_credit' => $credit,
                'is_balanced' => bccomp($debit, $credit, 2) === 0,
            ],
            'coverage' => $this->coverage(),
        ];
    }

    /**
     * One source document, summarised to its accounts.
     *
     * @param  Collection<int, object>  $group
     * @return array<string, mixed>
     */
    private function document(Collection $group): array
    {
        $first = $group->first();

        $rows = $group
            ->groupBy(fn (object $line) => $line->account_code . '|' . $line->entry_type)
            ->map(function (Collection $legs) {
                $leg = $legs->first();
                $amount = $legs->reduce(
                    fn (string $carry, object $l) => bcadd($carry, (string) $l->base_amount, 2),
                    '0.00',
                );

                return [
                    'account_code' => $leg->account_code,
                    'account_name' => $leg->account_name,
                    'debit' => $leg->entry_type === 'debit' ? $amount : '0.00',
                    'credit' => $leg->entry_type === 'credit' ? $amount : '0.00',
                ];
            })
            ->sortBy('account_code')
            ->values();

        return [
            'journal_no' => $first->document_ref,
            'posting_date' => $first->posting_date,
            'description' => $this->describe($group),
            'job_numbers' => $group->pluck('job_number')->filter()->unique()->values()->all(),
            'rows' => $rows->all(),
            'total_debit' => $rows->reduce(fn (string $c, array $r) => bcadd($c, $r['debit'], 2), '0.00'),
            'total_credit' => $rows->reduce(fn (string $c, array $r) => bcadd($c, $r['credit'], 2), '0.00'),
            'source_entries' => $group->pluck('entry_no')->unique()->values()->all(),
        ];
    }

    /**
     * A description a bookkeeper can act on without opening the ERP.
     *
     * One line's own description when the document is one line; otherwise what
     * it is and how many, because "Stores Issue: 18mm MDF (x4)" is misleading as
     * the label for a delivery of fourteen different materials.
     *
     * @param  Collection<int, object>  $group
     */
    private function describe(Collection $group): string
    {
        $descriptions = $group->pluck('line_description')->filter()->unique();

        if ($descriptions->count() === 1) {
            return (string) $descriptions->first();
        }

        $jobs = $group->pluck('job_number')->filter()->unique();
        $count = $group->pluck('cost_line_id')->unique()->count();

        return $jobs->count() === 1
            ? "{$count} cost lines, job {$jobs->first()}"
            : "{$count} cost lines across {$jobs->count()} jobs";
    }

    /**
     * Posted journal legs with their source document resolved.
     *
     * The document reference is taken from whichever operational key the
     * producer recorded, in the order a bookkeeper would recognise them: a
     * goods-received note, then the stores issue reference, then the purchase
     * order, then the voucher. `ref` is the last resort — a cost line captured
     * by hand IS its own document, so batching it under anything else would be
     * inventing a grouping that no piece of paper matches.
     *
     * @return Collection<int, object>
     */
    private function postedLines(string $from, string $to): Collection
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->leftJoin('cost_lines as cl', 'cl.id', '=', 'je.cost_line_id')
            ->whereIn('je.status', ['posted', 'reversed'])
            ->whereBetween('je.posting_date', [$from, $to])
            ->orderBy('je.posting_date')
            ->get([
                'je.id as journal_entry_id',
                'je.entry_no',
                DB::raw('DATE(je.posting_date) as posting_date'),
                'jl.entry_type',
                'jl.base_amount',
                'jl.description as line_description',
                'coa.code as account_code',
                'coa.name as account_name',
                'cl.id as cost_line_id',
                'cl.job_number',
                DB::raw(<<<'SQL'
                    COALESCE(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cl.details, '$.grn_number')), 'null'),
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cl.details, '$.stores_reference')), 'null'),
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cl.details, '$.po_number')), 'null'),
                        je.source_ref,
                        je.entry_no
                    ) as document_ref
                SQL),
            ]);
    }

    /**
     * What this export does NOT contain.
     *
     * Stated in the payload, not only in documentation, because the one way
     * this file causes harm is by being imported as though it were a complete
     * set of journals for the period. It is the cost side only. Whoever keys it
     * still posts revenue, payroll and bank movements from their own records.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        return [
            'includes' => [
                'Project and overhead costs verified in this system',
                'Recoverable input VAT and withholding tax on those costs',
                'Stores inventory movements and goods-received accruals',
                'Payroll accruals and payments explicitly posted from HR',
            ],
            'excludes' => [
                'Revenue, client invoices and receipts',
                'Payroll not explicitly posted from HR',
                'Bank and cash movements not raised as a spend voucher',
                'Opening balances, equity, depreciation and year-end adjustments',
            ],
            'warning' => 'Cost side only. This is a subledger hand-off, not a complete set of journals '
                . 'for the period, and importing it as one will not produce a balanced set of books.',
        ];
    }
}
