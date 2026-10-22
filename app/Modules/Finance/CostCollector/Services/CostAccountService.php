<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\FinanceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The project cost account, read.
 *
 * Every figure here comes from one table, which is the point of keeping budget
 * and spend together: variance is a GROUP BY rather than a reconciliation
 * between two systems that drift.
 */
class CostAccountService
{
    /**
     * Materials spend that names no element. Not an error — direct project
     * purchases and older lines predate the element being carried — but it is
     * labelled rather than hidden, because a growing bucket here means the
     * element grouping is drifting away from what is actually being spent.
     */
    public const ELEMENT_UNASSIGNED = 'Unassigned';

    /** Reporting label for spend with no budget category or planned-line link. */
    public const CATEGORY_UNBUDGETED = 'Unbudgeted costs';

    /**
     * How a material cost line is classified for grouping.
     *
     * Spend takes its element and material from the budget line it consumes, and
     * only falls back to its own when it consumes none. That is what makes a
     * rename work: correcting "BOOTH1" to "Booth 1" on the specification
     * re-projects the budget line, and every cost already charged against it
     * follows — where a snapshot taken at posting time would split one stand's
     * history into two elements that never reconcile again.
     */
    private const ELEMENT_EXPR = "COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(plan.details, '$.element')),
        JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.element')),
        ?
    )";

    private const MATERIAL_EXPR = "COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(plan.details, '$.material')),
        JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.material'))
    )";

    /**
     * A cost's own movement and return kind. Never inherited from the budget
     * line: the plan says what was intended, and whether a board came back is a
     * fact about the movement, not the plan.
     */
    private const MOVEMENT_EXPR = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.movement')), '')";

    private const RETURN_KIND_EXPR = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.return_kind')), 'whole_item')";

    private const LIBRARY_ID_EXPR = "COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(plan.details, '$.library_material_id')),
        JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.library_material_id'))
    )";

    /**
     * The budget category a cost line reports under: its own, else the planned line it
     * consumes, else unbudgeted. One binding: the unbudgeted label. Shared by the
     * project statement and the portfolio so the two cannot classify differently.
     */
    private const CATEGORY_EXPR = "COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.budget_category')),
        JSON_UNQUOTE(JSON_EXTRACT(
            (SELECT planned.details FROM cost_lines AS planned WHERE planned.id = cost_lines.consumes_line_id),
            '$.budget_category'
        )),
        CASE WHEN cost_lines.consumes_line_id IS NULL THEN ? ELSE 'Other project costs' END
    )";

    /**
     * Billed revenue for margin, in one place so the project statement and the
     * portfolio cannot measure it differently.
     *
     * `net` is what margin uses: the invoice total less output VAT. Credit
     * notes are stored negative on both columns, so they reduce all three
     * figures on the same basis. Report 76 P0-8: margin used to subtract
     * VAT-exclusive cost from the VAT-inclusive `total_amount`.
     */
    private const REVENUE_SUMS = '
        COALESCE(SUM(total_amount - COALESCE(tax_amount, 0)), 0) AS net,
        COALESCE(SUM(total_amount), 0) AS gross,
        COALESCE(SUM(COALESCE(tax_amount, 0)), 0) AS vat
    ';

    public const REVENUE_BASIS = 'net_of_output_vat';

    private const NATURE_SUMS = '
        SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS planned,
        SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS committed,
        SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS accrued,
        SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS actual,
        SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted
    ';

    private function natureBindings(): array
    {
        return [
            CostLine::NATURE_PLANNED,
            CostLine::NATURE_COMMITTED,
            CostLine::NATURE_ACCRUED,
            CostLine::NATURE_ACTUAL,
            CostLine::NATURE_PLANNED,
        ];
    }

    /**
     * The rows every figure on the accounts grid is summed from.
     *
     * Every filter here was previously accepted and then ignored: `index()` and
     * `grandTotals()` both took a `$filters` array, and only the project-status
     * branch was ever written — against a relation that did not exist. Nothing
     * on the screen could be narrowed by anything.
     *
     * `status` filters the PROJECT's status, not the cost line's. Cost lines are
     * already restricted to verified by `counting()`; what a finance user wants
     * is "show me live jobs only", because closed and cancelled projects
     * otherwise sit in the list with no way to exclude them.
     */
    private function accountQuery(array $filters = [])
    {
        return CostLine::query()
            ->counting()
            ->whereNotNull('project_enquiry_id')
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->whereHas('projectEnquiry', fn ($e) => $e->where('status', $status)),
            )
            ->when(
                $filters['cost_centre_id'] ?? null,
                fn ($q, $id) => $q->where('cost_centre_id', $id),
            )
            ->when(
                $filters['from'] ?? null,
                fn ($q, $from) => $q->where('incurred_at', '>=', Carbon::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'] ?? null,
                fn ($q, $to) => $q->where('incurred_at', '<=', Carbon::parse($to)->endOfDay()),
            )
            // Search runs over the project rather than the cost line: on this
            // screen a row IS a project, so "2451" means the job, not a
            // description that happens to contain it.
            ->when(
                filled($filters['q'] ?? null),
                fn ($q) => $q->whereHas('projectEnquiry', fn ($e) => $e
                    ->where('job_number', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('title', 'like', '%' . $filters['q'] . '%')),
            );
    }

    /**
     * Every project's cost account, one row each.
     *
     * Aggregated in SQL and paginated on the aggregate, not looped in PHP: the
     * equivalent petty-cash summary decodes JSON and sums in a foreach, which is
     * why it cannot scale past a few thousand projects (audit BE9/BE17).
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, string>, meta: array<string, int>}
     */
    public function index(array $filters = [], int $perPage = 25): array
    {
        $aggregate = $this->accountQuery($filters)
            ->selectRaw(
                'project_enquiry_id, MAX(incurred_at) AS last_cost_at, ' . self::NATURE_SUMS,
                $this->natureBindings(),
            )
            ->groupBy('project_enquiry_id');

        // These two are HAVING conditions, not WHERE: "overrun" and "has
        // unbudgeted spend" are properties of the summed row, so they cannot be
        // applied before the GROUP BY. They are the two questions this screen
        // exists to answer, and neither was askable.
        if (($filters['overrun_only'] ?? false) === true) {
            $aggregate->havingRaw(
                'SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) > 0
                 AND SUM(CASE WHEN nature <> ? THEN net_amount ELSE 0 END)
                     > SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END)',
                [CostLine::NATURE_PLANNED, CostLine::NATURE_PLANNED, CostLine::NATURE_PLANNED],
            );
        }

        if (($filters['unbudgeted_only'] ?? false) === true) {
            $aggregate->havingRaw(
                'SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) > 0',
                [CostLine::NATURE_PLANNED],
            );
        }

        $this->applySort($aggregate, $filters);

        $paginator = $aggregate->paginate(max(1, min($perPage, 100)));

        $enquiries = ProjectEnquiry::whereIn('id', collect($paginator->items())->pluck('project_enquiry_id'))
            ->get(['id', 'job_number', 'title', 'status'])
            ->keyBy('id');

        $rows = collect($paginator->items())->map(function ($row) use ($enquiries) {
            $enquiry = $enquiries->get($row->project_enquiry_id);
            $planned = $this->money($row->planned);
            $spent = bcadd(bcadd($this->money($row->actual), $this->money($row->accrued), 2), $this->money($row->committed), 2);

            return [
                'enquiry_id' => $row->project_enquiry_id,
                'job_number' => $enquiry?->job_number,
                'title' => $enquiry?->title,
                'status' => $enquiry?->status,
                // A project whose last cost landed months ago is either finished
                // or forgotten, and the grid could not tell you which.
                'last_cost_at' => $row->last_cost_at ? substr((string) $row->last_cost_at, 0, 10) : null,
                'planned' => $planned,
                'committed' => $this->money($row->committed),
                'accrued' => $this->money($row->accrued),
                'actual' => $this->money($row->actual),
                'spent' => $spent,
                'unbudgeted' => $this->money($row->unbudgeted),
                'remaining' => bcsub($planned, $spent, 2),
                'utilisation_percent' => bccomp($planned, '0', 2) === 1
                    ? round((float) bcdiv($spent, $planned, 4) * 100, 1)
                    : null,
            ];
        })->all();

        return [
            'rows' => $rows,
            // Totals across ALL projects, not just this page — a page total in a
            // financial table invites being read as the whole.
            'totals' => $this->grandTotals($filters),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Sort on any money column.
     *
     * The aliases are the aggregate's own, so ordering happens in SQL across
     * every page rather than on the 25 rows that happen to be in hand. Whitelist
     * only — these names reach the ORDER BY clause.
     */
    private function applySort($query, array $filters): void
    {
        $sortable = [
            'planned' => 'planned',
            'committed' => 'committed',
            'accrued' => 'accrued',
            'actual' => 'actual',
            'unbudgeted' => 'unbudgeted',
            'last_cost' => 'last_cost_at',
        ];

        $column = $sortable[$filters['sort'] ?? ''] ?? null;
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Biggest spend first by default: on a list of cost accounts the useful
        // starting point is where the money is, not the lowest project id.
        $query->orderByRaw(
            $column ? "{$column} {$direction}" : 'actual desc',
        );
    }

    /** @return array<string, string> */
    private function grandTotals(array $filters = []): array
    {
        $row = $this->accountQuery($filters)
            ->selectRaw(self::NATURE_SUMS, $this->natureBindings())
            ->first();

        $planned = $this->money($row?->planned);
        $spent = bcadd(bcadd($this->money($row?->actual), $this->money($row?->accrued), 2), $this->money($row?->committed), 2);

        return [
            'planned' => $planned,
            'committed' => $this->money($row?->committed),
            'accrued' => $this->money($row?->accrued),
            'actual' => $this->money($row?->actual),
            'spent' => $spent,
            'unbudgeted' => $this->money($row?->unbudgeted),
            'remaining' => bcsub($planned, $spent, 2),
        ];
    }

    /** A decimal SQL sum as an exact 2dp string — never through a float. */
    private function money(mixed $value): string
    {
        return bcadd(is_numeric($value) ? (string) $value : '0', '0', 2);
    }

    /** @return array<string, mixed> */
    public function forEnquiry(ProjectEnquiry $enquiry): array
    {
        $rows = CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->counting()
            ->selectRaw(self::CATEGORY_EXPR . " AS category,
                nature,
                SUM(net_amount) AS total,
                SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted,
                COUNT(*) AS line_count
            ", [self::CATEGORY_UNBUDGETED, CostLine::NATURE_PLANNED])
            ->groupBy('category', 'nature')
            ->get();

        $categories = $this->pivotByCategory($rows);
        $totals = $this->totals($categories);
        $margin = $this->marginAgainstJournals($enquiry);

        return [
            'project' => [
                'enquiry_id'               => $enquiry->id,
                'job_number'               => $enquiry->job_number,
                'title'                    => $enquiry->title,
                // W6-5: financial closure status — Finance control, independent
                // of the project's operational status.
                'financial_closure_status' => $enquiry->financial_closure_status ?? 'open',
                'financially_closed_at'    => $enquiry->financially_closed_at?->toIso8601String(),
            ],
            'totals' => $totals,
            'categories' => $categories,
            'elements' => $this->elementBreakdown($enquiry),
            'unbudgeted' => $this->unbudgeted($enquiry),
            'exceptions' => $this->exceptionSpend($enquiry),
            'coverage' => $this->coverage($enquiry),
            // Cash and billing sit beside the cost statement so they cannot be
            // mistaken for another spend column (paying ≠ costing).
            'cash_movements' => $this->cashMovements($enquiry),
            'margin' => $margin,
            'alerts' => $this->alerts($totals, $margin),
        ];
    }

    /**
     * Portfolio-level margin for multiple projects — one batched query, not N+1.
     *
     * W6-2: The existing index() method intentionally omits margin (portfolio is
     * already expensive). This method is the opt-in margin view, called when Finance
     * explicitly requests margin data for a set of projects. All needed data is
     * batch-queried upfront so the frontend sees one round trip, not one per project.
     *
     * @param  int[]  $enquiryIds
     * @return array<int, array<string, mixed>>
     */
    public function portfolioMargin(array $enquiryIds): array
    {
        if (empty($enquiryIds)) {
            return [];
        }

        // Batch 1: billed revenue per enquiry.
        $billedByEnquiry = DB::table('project_invoices')
            ->whereIn('project_enquiry_id', $enquiryIds)
            ->whereNot('status', 'void')
            ->whereNotNull('journal_entry_id')
            ->selectRaw('project_enquiry_id, ' . self::REVENUE_SUMS)
            ->groupBy('project_enquiry_id')
            ->get()
            ->keyBy('project_enquiry_id');

        // Batch 2: WIP released (COS journal lines) per enquiry.
        $releasedByEnquiry = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->whereIn('jl.project_enquiry_id', $enquiryIds)
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.source_type', \App\Modules\Finance\Models\ProjectInvoice::class)
            ->where(function ($q) {
                $q->where('coa.code', 'like', '5%')
                    ->orWhere('coa.code', 'like', 'COS-%');
            })
            ->selectRaw(
                'jl.project_enquiry_id, '
                . "COALESCE(SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE -jl.base_amount END), 0) AS released",
            )
            ->groupBy('jl.project_enquiry_id')
            ->pluck('released', 'project_enquiry_id');

        // Batch 3a: verified actual direct cost per enquiry (excluding allocated parent lines).
        $directByEnquiry = CostLine::query()
            ->whereIn('project_enquiry_id', $enquiryIds)
            ->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->whereDoesntHave('allocations')
            ->selectRaw('project_enquiry_id, SUM(net_amount) AS actual')
            ->groupBy('project_enquiry_id')
            ->pluck('actual', 'project_enquiry_id');

        // Batch 3b: verified actual allocated slices per enquiry.
        $allocatedByEnquiry = \App\Modules\Finance\CostCollector\Models\CostLineAllocation::query()
            ->whereIn('project_enquiry_id', $enquiryIds)
            ->whereHas('costLine', fn ($q) => $q->counting()->where('nature', CostLine::NATURE_ACTUAL))
            ->selectRaw('project_enquiry_id, SUM(allocated_amount) AS allocated')
            ->groupBy('project_enquiry_id')
            ->pluck('allocated', 'project_enquiry_id');

        // Batch 4: agreed quote amounts per enquiry (latest approved only).
        $agreedByEnquiry = DB::table('quote_approvals')
            ->whereIn('enquiry_id', $enquiryIds)
            ->where('approval_status', 'approved')
            ->orderByDesc('updated_at')
            ->get(['enquiry_id', 'quote_amount'])
            ->unique('enquiry_id')
            ->pluck('quote_amount', 'enquiry_id');

        // Batch 5 (W7): verified Actual Labour per enquiry, classified with exactly the
        // category expression forEnquiry() uses, so a portfolio figure always equals
        // the sum of the matching project statements.
        $labourByEnquiry = CostLine::query()
            ->whereIn('project_enquiry_id', $enquiryIds)
            ->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->whereRaw(self::CATEGORY_EXPR . ' = ?', [self::CATEGORY_UNBUDGETED, 'labour'])
            ->selectRaw('project_enquiry_id, SUM(net_amount) AS labour')
            ->groupBy('project_enquiry_id')
            ->pluck('labour', 'project_enquiry_id');

        // W7: Pre-load enquiry IDs that have at least one Finance-verified labour actual.
        // Single query to avoid N+1 per enquiry in the loop below.
        $enquiriesWithLabourActuals = \App\Modules\Finance\CostCollector\Models\ProjectLabourActual::query()
            ->whereIn('project_enquiry_id', $enquiryIds)
            ->where('status', \App\Modules\Finance\CostCollector\Models\ProjectLabourActual::STATUS_FINANCE_VERIFIED)
            ->distinct()
            ->pluck('project_enquiry_id')
            ->flip()     // convert to [id => index] for O(1) lookup
            ->all();

        $costCompleteness = [
            'materials'   => 'included',
            'procurement' => 'included',
            'expenses'    => 'included',
            'labour'      => null,          // resolved per-enquiry below using $enquiriesWithLabourActuals
            'logistics'   => 'not_included', // W8 — pending WNG decision
            'overhead'    => 'not_included', // overhead allocation — pending WNG decision
        ];

        $result = [];
        foreach ($enquiryIds as $enquiryId) {
            // Same basis as marginAgainstJournals(): revenue net of output VAT.
            $billed = $this->money($billedByEnquiry[$enquiryId]->net ?? 0);
            $billedGross = $this->money($billedByEnquiry[$enquiryId]->gross ?? 0);
            $released = $this->money($releasedByEnquiry[$enquiryId] ?? 0);
            $directActual = (string) ($directByEnquiry[$enquiryId] ?? 0);
            $allocatedActual = (string) ($allocatedByEnquiry[$enquiryId] ?? 0);
            $actual = $this->money(bcadd($directActual, $allocatedActual, 2));

            $usesRelease = bccomp($released, '0.00', 2) === 1;
            $costOfSales = $usesRelease ? $released : $actual;
            $basis = $usesRelease ? 'released' : 'actual';
            $margin = bcsub($billed, $costOfSales, 2);
            $marginPercent = bccomp($billed, '0.00', 2) === 1
                ? round((float) bcmul(bcdiv($margin, $billed, 6), '100', 4), 1)
                : null;

            $agreed = (float) ($agreedByEnquiry[$enquiryId] ?? 0);
            // Invoice total over agreed quote, as the WIP release uses it.
            $fraction = ($agreed > 0 && bccomp($billedGross, '0.00', 2) === 1)
                ? bcdiv($billedGross, $this->money($agreed), 6)
                : '0.000000';
            if (bccomp($fraction, '1.000000', 6) > 0) {
                $fraction = '1.000000';
            }

            $enquiryCompleteness = $costCompleteness;
            $enquiryCompleteness['labour'] = array_key_exists($enquiryId, $enquiriesWithLabourActuals)
                ? 'included'
                : 'not_included';

            $result[$enquiryId] = [
                'billed_revenue'         => $billed,
                'revenue_basis'          => self::REVENUE_BASIS,
                'billed_gross'           => $billedGross,
                'output_vat'             => $this->money($billedByEnquiry[$enquiryId]->vat ?? 0),
                'actual_labour'          => $this->money($labourByEnquiry[$enquiryId] ?? 0),
                'cost_of_sales'          => $costOfSales,
                'cost_basis'             => $basis,
                'margin'                 => $margin,
                'margin_percent'         => $marginPercent,
                'billed_fraction'        => $fraction,
                'cost_completeness'      => $enquiryCompleteness,
                'margin_type'            => 'direct',
                'margin_status'          => 'provisional',
                'fully_loaded_available' => false,
            ];
        }

        return $result;
    }

    /**
     * Brief §9's three thresholds, read against the figures already computed
     * above rather than recomputed.
     *
     * Advisory only — none of these block anything, they flag. `FinanceSetting`
     * itself draws the line: `value()` is right for "a warning threshold that
     * is merely proposed is still better guidance than nothing", and a hard
     * block is the only thing that must wait for `approvedValue()`. Seeded
     * unapproved and read through `value()` regardless, so a threshold Finance
     * has not yet signed off still shows a project running hot rather than
     * showing nothing.
     *
     * A missing setting must never manufacture an alert nobody configured, so
     * a null threshold turns every flag off rather than defaulting to some
     * guessed number.
     */
    private function alerts(array $totals, array $margin): array
    {
        $overrunThreshold = FinanceSetting::value('cost_overrun_alert_percent');
        $marginWarning = FinanceSetting::value('margin_warning_percent');
        $marginEscalation = FinanceSetting::value('margin_escalation_percent');

        $overrunPercent = $totals['utilisation_percent'] !== null
            ? $totals['utilisation_percent'] - 100
            : null;
        $marginPercent = $margin['margin_percent'];

        return [
            'cost_overrun' => $overrunThreshold !== null && $overrunPercent !== null
                && $overrunPercent > (float) $overrunThreshold,
            'cost_overrun_threshold_percent' => $overrunThreshold !== null ? (float) $overrunThreshold : null,
            'margin_warning' => $marginWarning !== null && $marginPercent !== null
                && $marginPercent < (float) $marginWarning,
            'margin_warning_threshold_percent' => $marginWarning !== null ? (float) $marginWarning : null,
            'margin_escalation' => $marginEscalation !== null && $marginPercent !== null
                && $marginPercent < (float) $marginEscalation,
            'margin_escalation_threshold_percent' => $marginEscalation !== null ? (float) $marginEscalation : null,
        ];
    }

    /**
     * Cash paid out against this job — custody facts, not project cost.
     *
     * @return array{paid_out: string, payment_count: int, note: string}
     */
    private function cashMovements(ProjectEnquiry $enquiry): array
    {
        $row = DB::table('payments')
            ->where('project_enquiry_id', $enquiry->id)
            ->whereNull('voided_at')
            ->where(function ($q) {
                $q->whereNull('is_archived')->orWhere('is_archived', false);
            })
            ->selectRaw('COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS paid_out')
            ->first();

        return [
            'paid_out' => $this->money($row->paid_out ?? 0),
            'payment_count' => (int) ($row->payment_count ?? 0),
            'note' => 'Cash leaving the business for this job. Not added to budget-versus-actual; costs already sit in verified cost lines.',
        ];
    }

    /**
     * Job margin from the subledger: billed revenue versus cost released to
     * Cost of Sales (or verified actuals when nothing has been released yet).
     *
     * @return array{
     *   billed_revenue: string,
     *   cost_of_sales: string,
     *   cost_basis: 'released'|'actual',
     *   margin: string,
     *   margin_percent: float|null,
     *   billed_fraction: string,
     *   note: string
     * }
     */
    private function marginAgainstJournals(ProjectEnquiry $enquiry): array
    {
        $invoiced = DB::table('project_invoices')
            ->where('project_enquiry_id', $enquiry->id)
            ->whereNot('status', 'void')
            ->whereNotNull('journal_entry_id')
            ->selectRaw(self::REVENUE_SUMS)
            ->first();

        // Margin is measured on revenue net of output VAT: the tax is collected
        // for the Authority, not earned, and every cost it is compared with is
        // already net of recoverable input VAT. `billed_gross` is what the client
        // was asked to pay and is kept for display and for the billed share.
        $billed = $this->money($invoiced->net ?? 0);
        $billedGross = $this->money($invoiced->gross ?? 0);
        $outputVat = $this->money($invoiced->vat ?? 0);

        $released = $this->money(
            DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
                ->where('jl.project_enquiry_id', $enquiry->id)
                ->whereIn('je.status', ['posted', 'reversed'])
                ->where('je.source_type', \App\Modules\Finance\Models\ProjectInvoice::class)
                ->where(function ($q) {
                    $q->where('coa.code', 'like', '5%')
                        ->orWhere('coa.code', 'like', 'COS-%');
                })
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE -jl.base_amount END), 0) as released",
                )
                ->value('released'),
        );

        $directActual = CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)
            ->whereDoesntHave('allocations')
            ->sum('net_amount');

        $allocatedIn = \App\Modules\Finance\CostCollector\Models\CostLineAllocation::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->whereHas('costLine', fn ($q) => $q->counting()->where('nature', CostLine::NATURE_ACTUAL))
            ->sum('allocated_amount');

        $actual = $this->money(bcadd((string) $directActual, (string) $allocatedIn, 2));

        $usesRelease = bccomp($released, '0.00', 2) === 1;
        $costOfSales = $usesRelease ? $released : $actual;
        $basis = $usesRelease ? 'released' : 'actual';
        $margin = bcsub($billed, $costOfSales, 2);
        $marginPercent = bccomp($billed, '0.00', 2) === 1
            ? round((float) bcmul(bcdiv($margin, $billed, 6), '100', 4), 1)
            : null;

        $agreed = (float) DB::table('quote_approvals')
            ->where('enquiry_id', $enquiry->id)
            ->where('approval_status', 'approved')
            ->orderByDesc('updated_at')
            ->value('quote_amount');

        // Deliberately still the invoice total over the agreed quote: this is
        // the figure WorkInProgressReleaseService::billedFraction() releases
        // cost by, and whether quotes are stated with or without VAT is a WNG
        // confirmation that has not been given (Report 76 §32 item 8).
        $fraction = ($agreed > 0 && bccomp($billedGross, '0.00', 2) === 1)
            ? bcdiv($billedGross, $this->money($agreed), 6)
            : '0.000000';

        if (bccomp($fraction, '1.000000', 6) > 0) {
            $fraction = '1.000000';
        }

        // W6-1 / W7: cost completeness — which categories are included in this margin.
        // Labour is now dynamic: 'included' if at least one Finance-verified labour actual exists.
        $hasLabourActuals = \App\Modules\Finance\CostCollector\Models\ProjectLabourActual::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->where('status', \App\Modules\Finance\CostCollector\Models\ProjectLabourActual::STATUS_FINANCE_VERIFIED)
            ->exists();

        $costCompleteness = [
            'materials'   => 'included',   // stores issues + direct materials
            'procurement' => 'included',   // verified procurement cost lines
            'expenses'    => 'included',   // verified expense/petty-cash cost lines
            'labour'      => $hasLabourActuals ? 'included' : 'not_included', // W7 confirmed subset
            'logistics'   => 'not_included', // W8 — pending WNG decision
            'overhead'    => 'not_included', // overhead allocation — pending WNG decision
        ];

        return [
            'billed_revenue'         => $billed,
            'revenue_basis'          => self::REVENUE_BASIS,
            'billed_gross'           => $billedGross,
            'output_vat'             => $outputVat,
            'cost_of_sales'          => $costOfSales,
            'cost_basis'             => $basis,
            'margin'                 => $margin,
            'margin_percent'         => $marginPercent,
            'billed_fraction'        => $fraction,
            'note'                   => $usesRelease
                ? 'Cost of sales is Work in Progress released against issued invoices.'
                : 'No WIP→COS release yet; margin uses verified actual costs until billing releases them.',
            'cost_completeness'      => $costCompleteness,
            'margin_type'            => 'direct',
            'margin_status'          => 'provisional', // always provisional until W7/W8/overhead resolved
            'fully_loaded_available' => false,         // W6-1A unconfirmed — labour/logistics not in scope
        ];
    }

    /**
     * Every verified material line on a project, joined to the budget line it
     * consumes so classification can be read from the plan first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CostLine>
     */
    private function materialLinesWithClassification(ProjectEnquiry $enquiry)
    {
        return CostLine::query()
            ->leftJoin('cost_lines AS plan', 'plan.id', '=', 'cost_lines.consumes_line_id')
            ->where('cost_lines.project_enquiry_id', $enquiry->id)
            ->where('cost_lines.status', CostLine::STATUS_VERIFIED)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(cost_lines.details, '$.budget_category')) = 'materials'");
    }

    /**
     * Materials budget against materials spend, per project element.
     *
     * A category total answers "what did materials cost?" — useful, but nobody
     * builds a category. They build a reception desk, a stage, a backdrop, and
     * that is the unit a project manager plans, buys and is asked about. The
     * element was already the shape of the materials list and the budget; it was
     * only the cost account that flattened it away.
     *
     * Materials only: labour, expenses and logistics are flat by nature and have
     * no element to group on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function elementBreakdown(ProjectEnquiry $enquiry): array
    {
        $rows = $this->materialLinesWithClassification($enquiry)
            ->selectRaw(
                self::ELEMENT_EXPR . ' AS element, '
                . self::MATERIAL_EXPR . ' AS material, '
                . self::LIBRARY_ID_EXPR . ' AS library_material_id, '
                . 'cost_lines.nature, '
                . 'SUM(cost_lines.net_amount) AS total, '
                . 'SUM(CASE WHEN cost_lines.nature <> ? AND cost_lines.consumes_line_id IS NULL '
                . '    THEN cost_lines.net_amount ELSE 0 END) AS unbudgeted, '
                // What went out to the project, before anything came back. The
                // net alone cannot say whether a material cost less because it
                // was bought well or because half of it came back.
                . 'SUM(CASE WHEN ' . self::MOVEMENT_EXPR . " <> 'return_credit' "
                . '    THEN cost_lines.net_amount ELSE 0 END) AS issued, '
                // Unused stock handed straight back: the project no longer needs
                // it and its requirement reopens.
                . 'SUM(CASE WHEN ' . self::MOVEMENT_EXPR . " = 'return_credit' "
                . '    AND ' . self::RETURN_KIND_EXPR . " <> 'recovered_offcut' "
                . '    THEN cost_lines.net_amount ELSE 0 END) AS returned, '
                // The usable remnant of a board the project did consume. It
                // reduces cost but owes the project nothing, which is exactly why
                // it must not be read as a return.
                . 'SUM(CASE WHEN ' . self::MOVEMENT_EXPR . " = 'return_credit' "
                . '    AND ' . self::RETURN_KIND_EXPR . " = 'recovered_offcut' "
                . '    THEN cost_lines.net_amount ELSE 0 END) AS offcut_recovered, '
                . 'COUNT(*) AS line_count',
                [self::ELEMENT_UNASSIGNED, CostLine::NATURE_PLANNED],
            )
            ->groupBy('element', 'material', 'library_material_id', 'cost_lines.nature')
            ->get();

        // A catalogue id is the stronger identity — two elements can order the
        // same board under slightly different wording — but plenty of lines carry
        // only a name, so the name keys those.
        $keyOf = fn ($row) => filled($row->library_material_id)
            ? 'lib:' . $row->library_material_id
            : 'name:' . mb_strtolower(trim((string) ($row->material ?? '')));

        return $rows->groupBy('element')->map(function ($elementRows, $element) use ($keyOf) {
            $materials = $elementRows->groupBy($keyOf)->map(function ($group) {
                $of = fn (string $nature) => (string) number_format(
                    (float) $group->where('nature', $nature)->sum('total'), 2, '.', ''
                );

                $planned = $of(CostLine::NATURE_PLANNED);
                $spent = bcadd(
                    bcadd($of(CostLine::NATURE_ACTUAL), $of(CostLine::NATURE_ACCRUED), 2),
                    $of(CostLine::NATURE_COMMITTED),
                    2,
                );

                $named = $group->first(fn ($row) => filled($row->material));
                $sum = fn (string $column) => (string) number_format(
                    (float) $group->sum(fn ($row) => (float) $row->{$column}), 2, '.', ''
                );

                // Credits are stored negative. Reported as the positive amounts
                // they represent, because "returned −1,500" reads as a deduction
                // from a deduction and nobody parses it correctly at a glance.
                $returned = bcmul($sum('returned'), '-1', 2);
                $offcut = bcmul($sum('offcut_recovered'), '-1', 2);

                return [
                    'material' => $named->material ?? 'Unnamed material',
                    'library_material_id' => filled($group->first()->library_material_id)
                        ? (int) $group->first()->library_material_id
                        : null,
                    'planned' => $planned,
                    // issued − returned − offcut === spent, so the row shows its
                    // own arithmetic rather than a net figure the reader has to
                    // take on trust.
                    'issued' => $sum('issued'),
                    'returned' => $returned,
                    'offcut_recovered' => $offcut,
                    'has_offcut' => bccomp($offcut, '0.00', 2) !== 0,
                    'spent' => $spent,
                    'unbudgeted' => $sum('unbudgeted'),
                    'remaining' => bcsub($planned, $spent, 2),
                    'utilisation_percent' => bccomp($planned, '0.00', 2) === 1
                        ? round((float) bcdiv($spent, $planned, 4) * 100, 1)
                        : null,
                ];
            })->sortByDesc(fn ($row) => (float) $row['planned'])->values()->all();

            // The element's own figures come from its rows, not from summing the
            // material rows above: the materials are grouped by catalogue
            // identity, and a nature split has to survive that grouping to be
            // reported here at all.
            $of = fn (string $nature) => (string) number_format(
                (float) $elementRows->where('nature', $nature)->sum('total'), 2, '.', ''
            );

            $planned = $of(CostLine::NATURE_PLANNED);
            $committed = $of(CostLine::NATURE_COMMITTED);
            $accrued = $of(CostLine::NATURE_ACCRUED);
            $actual = $of(CostLine::NATURE_ACTUAL);
            $spent = bcadd(bcadd($actual, $accrued, 2), $committed, 2);

            return [
                'element' => (string) $element,
                'planned' => $planned,
                'committed' => $committed,
                'accrued' => $accrued,
                'actual' => $actual,
                'spent' => $spent,
                'unbudgeted' => (string) number_format(
                    (float) $elementRows->sum(fn ($row) => (float) $row->unbudgeted), 2, '.', ''
                ),
                'remaining' => bcsub($planned, $spent, 2),
                'utilisation_percent' => bccomp($planned, '0.00', 2) === 1
                    ? round((float) bcdiv($spent, $planned, 4) * 100, 1)
                    : null,
                'line_count' => (int) $elementRows->sum(fn ($row) => (int) $row->line_count),
                'materials' => $materials,
            ];
        })->sortByDesc(fn (array $row) => (float) $row['planned'])->values()->all();
    }

    /**
     * The lines behind one category figure.
     *
     * The panel showed category totals and a variance, and clicking a category
     * did nothing — so there was no path from "materials is 40% over" to the
     * costs causing it, which is the only question the screen is asked. The
     * budget line and the spend against it come back together, because a
     * variance is read as a pair.
     *
     * @return array<string, mixed>
     */
    public function linesForCategory(ProjectEnquiry $enquiry, string $category): array
    {
        $lines = CostLine::withReferenceNames()
            ->with([
                'expenseCode',
                'submittedBy',
                'voucherAllocations.voucher',
            ])
            ->where('project_enquiry_id', $enquiry->id)
            ->counting()
            ->where(function ($q) use ($category) {
                // The unbudgeted label is what `forEnquiry` gives rows with no
                // budget_category, so the drill-down has to match that absence
                // rather than look for the literal string.
                $extract = "JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category'))";
                $plannedExtract = "JSON_UNQUOTE(JSON_EXTRACT(
                    (SELECT planned.details FROM cost_lines AS planned WHERE planned.id = cost_lines.consumes_line_id),
                    '$.budget_category'
                ))";

                if (in_array(mb_strtolower($category), [
                    mb_strtolower(self::CATEGORY_UNBUDGETED),
                    'uncategorised',
                ], true)) {
                    $q->whereNull('consumes_line_id')->whereRaw("{$extract} IS NULL");
                } elseif ($category === 'Other project costs') {
                    $q->whereNotNull('consumes_line_id')->whereRaw("COALESCE({$extract}, {$plannedExtract}) IS NULL");
                } else {
                    $q->whereRaw("COALESCE({$extract}, {$plannedExtract}) = ?", [$category]);
                }
            })
            ->orderBy('nature')
            ->orderByDesc('net_amount')
            ->get();

        $planned = $lines->where('nature', CostLine::NATURE_PLANNED)->values();
        $spend = $lines->where('nature', '!=', CostLine::NATURE_PLANNED)->values();

        return [
            'category' => $category,
            'planned' => $planned,
            'spend' => $spend,
            // Materials are read per element, so the drill-down offers the same
            // shape as the summary above it rather than one long flat list the
            // reader has to re-group in their head. Other categories are flat by
            // nature and get no grouping.
            'elements' => $category === 'materials'
                ? $this->groupLinesByElement($planned, $spend)
                : [],
        ];
    }

    /**
     * Pair each element's budget lines with the spend that claimed them.
     *
     * @param  \Illuminate\Support\Collection<int, CostLine>  $planned
     * @param  \Illuminate\Support\Collection<int, CostLine>  $spend
     * @return array<int, array<string, mixed>>
     */
    private function groupLinesByElement($planned, $spend): array
    {
        // Same rule as the summary above: the budget line's classification wins,
        // so a renamed element does not split into two.
        $plannedElements = $planned->pluck('details.element', 'id');

        $elementOf = function (CostLine $line) use ($plannedElements) {
            $fromPlan = $line->consumes_line_id
                ? $plannedElements->get($line->consumes_line_id)
                : null;

            return filled($fromPlan)
                ? (string) $fromPlan
                : (filled($line->details['element'] ?? null)
                    ? (string) $line->details['element']
                    : self::ELEMENT_UNASSIGNED);
        };

        $plannedByElement = $planned->groupBy($elementOf);
        $spendByElement = $spend->groupBy($elementOf);

        return $plannedByElement->keys()
            ->merge($spendByElement->keys())
            ->unique()
            ->sort()
            ->map(fn (string $element) => [
                'element' => $element,
                'planned' => $plannedByElement->get($element, collect())->values(),
                'spend' => $spendByElement->get($element, collect())->values(),
                'planned_total' => $this->money($plannedByElement->get($element, collect())->sum('net_amount')),
                'spend_total' => $this->money($spendByElement->get($element, collect())->sum('net_amount')),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pivotByCategory($rows): array
    {
        return $rows->groupBy('category')->map(function ($group, $category) {
            $of = fn (string $nature) => $this->money($group->firstWhere('nature', $nature)->total ?? 0);

            $planned = $of(CostLine::NATURE_PLANNED);
            $spent = bcadd(
                bcadd($of(CostLine::NATURE_ACTUAL), $of(CostLine::NATURE_ACCRUED), 2),
                $of(CostLine::NATURE_COMMITTED),
                2,
            );

            return [
                'category' => $category,
                'planned' => $planned,
                'committed' => $of(CostLine::NATURE_COMMITTED),
                'accrued' => $of(CostLine::NATURE_ACCRUED),
                'actual' => $of(CostLine::NATURE_ACTUAL),
                // The figure every remaining and utilisation number is derived
                // from. It was computed here and thrown away, leaving the reader
                // to add three columns to check a fourth.
                'spent' => $spent,
                // Already inside `spent` — surfaced per category so an overrun
                // can be read as planned-but-expensive or simply unplanned.
                'unbudgeted' => $group->reduce(fn ($carry, $row) => bcadd($carry, $this->money($row->unbudgeted), 2), '0.00'),
                'remaining' => bcsub($planned, $spent, 2),
                // Negative planned with spend against it is an overrun; the sign
                // is left as-is so the client does not have to guess direction.
                'utilisation_percent' => bccomp($planned, '0', 2) === 1
                    ? round((float) bcdiv($spent, $planned, 4) * 100, 1)
                    : null,
            ];
        })->values()->all();
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function totals(array $categories): array
    {
        $sum = fn (string $key) => array_reduce(
            $categories, fn ($carry, $row) => bcadd($carry, $row[$key], 2), '0.00'
        );

        $planned = $sum('planned');
        $spent = bcadd(bcadd($sum('actual'), $sum('accrued'), 2), $sum('committed'), 2);

        return [
            'planned' => $planned,
            'committed' => $sum('committed'),
            'accrued' => $sum('accrued'),
            'actual' => $sum('actual'),
            'spent' => $spent,
            'unbudgeted' => $sum('unbudgeted'),
            'remaining' => bcsub($planned, $spent, 2),
            'utilisation_percent' => bccomp($planned, '0', 2) === 1
                ? round((float) bcdiv($spent, $planned, 4) * 100, 1)
                : null,
        ];
    }

    /**
     * Spend that claimed no budget line. The single most useful number on the
     * screen — it is where money leaves without anyone having planned for it.
     */
    private function unbudgeted(ProjectEnquiry $enquiry): array
    {
        $lines = CostLine::with('expenseCode')
            ->where('project_enquiry_id', $enquiry->id)
            ->where('nature', '!=', CostLine::NATURE_PLANNED)
            ->whereNull('consumes_line_id')
            ->counting()
            ->orderByDesc('net_amount')
            ->get();

        return [
            'total' => (string) number_format((float) $lines->sum('net_amount'), 2, '.', ''),
            'count' => $lines->count(),
            'lines' => $lines->map(fn (CostLine $line) => [
                'id' => $line->id,
                'ref' => $line->ref,
                'description' => $line->description,
                'expense_family' => $line->expenseCode?->expense_family,
                'expense_type' => $line->expenseCode?->expense_type,
                'nature' => $line->nature,
                'nature_label' => match ($line->nature) {
                    CostLine::NATURE_COMMITTED => 'Ordered / approved',
                    CostLine::NATURE_ACCRUED => 'Received, not invoiced',
                    CostLine::NATURE_ACTUAL => 'Posted actual',
                    default => ucfirst($line->nature),
                },
                'unbudgeted_reason' => $line->details['unbudgeted_reason'] ?? null,
                'net_amount' => $line->net_amount,
                'incurred_at' => $line->incurred_at?->toDateString(),
            ])->all(),
        ];
    }

    /**
     * Grouped on cost_causes.is_exception rather than a hardcoded list, so a
     * cause added to the reference data later appears here automatically.
     */
    private function exceptionSpend(ProjectEnquiry $enquiry): array
    {
        return CostLine::query()
            ->where('cost_lines.project_enquiry_id', $enquiry->id)
            ->where('cost_lines.nature', '!=', CostLine::NATURE_PLANNED)
            ->counting()
            ->join('cost_causes', 'cost_causes.id', '=', 'cost_lines.cost_cause_id')
            ->where('cost_causes.is_exception', true)
            ->groupBy('cost_causes.code', 'cost_causes.name')
            ->select('cost_causes.code', 'cost_causes.name', DB::raw('SUM(cost_lines.net_amount) as total'))
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'total' => (string) number_format((float) $row->total, 2, '.', ''),
            ])->all();
    }

    /**
     * How much of the budget has been answered at all.
     *
     * A planned line nothing has been spent against is not necessarily a saving —
     * it may simply be a cost nobody has reported yet. Distinguishing those two
     * is what makes a cost account closeable rather than merely current.
     */
    private function coverage(ProjectEnquiry $enquiry): array
    {
        $planned = CostLine::where('project_enquiry_id', $enquiry->id)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->counting();

        $total = (clone $planned)->count();
        $answered = (clone $planned)->whereHas('consumers', fn ($q) => $q->counting())->count();

        return [
            'planned_lines' => $total,
            'lines_with_spend' => $answered,
            'lines_awaiting' => $total - $answered,
            'percent' => $total > 0 ? round($answered / $total * 100, 1) : null,
        ];
    }
}
