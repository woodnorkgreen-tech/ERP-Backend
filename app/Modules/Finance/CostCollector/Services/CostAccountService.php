<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
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

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /** @return array<string, mixed> */
    public function forEnquiry(ProjectEnquiry $enquiry): array
    {
        $rows = CostLine::query()
            ->where('project_enquiry_id', $enquiry->id)
            ->counting()
            ->selectRaw("
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')), 'uncategorised') AS category,
                nature,
                SUM(net_amount) AS total,
                SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted,
                COUNT(*) AS line_count
            ", [CostLine::NATURE_PLANNED])
            ->groupBy('category', 'nature')
            ->get();

        $categories = $this->pivotByCategory($rows);

        return [
            'project' => [
                'enquiry_id' => $enquiry->id,
                'job_number' => $enquiry->job_number,
                'title' => $enquiry->title,
            ],
            'totals' => $this->totals($categories),
            'categories' => $categories,
            'unbudgeted' => $this->unbudgeted($enquiry),
            'exceptions' => $this->exceptionSpend($enquiry),
            'coverage' => $this->coverage($enquiry),
        ];
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
            ->with(['expenseCode', 'submittedBy'])
            ->where('project_enquiry_id', $enquiry->id)
            ->counting()
            ->where(function ($q) use ($category) {
                // 'uncategorised' is the label `forEnquiry` gives rows with no
                // budget_category, so the drill-down has to match that absence
                // rather than look for the literal string.
                $extract = "JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category'))";

                $category === 'uncategorised'
                    ? $q->whereRaw("COALESCE({$extract}, 'uncategorised') = 'uncategorised'")
                    : $q->whereRaw("{$extract} = ?", [$category]);
            })
            ->orderBy('nature')
            ->orderByDesc('net_amount')
            ->get();

        return [
            'category' => $category,
            'planned' => $lines->where('nature', CostLine::NATURE_PLANNED)->values(),
            'spend' => $lines->where('nature', '!=', CostLine::NATURE_PLANNED)->values(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function pivotByCategory($rows): array
    {
        return $rows->groupBy('category')->map(function ($group, $category) {
            $of = fn (string $nature) => (string) number_format(
                (float) ($group->firstWhere('nature', $nature)->total ?? 0), 2, '.', ''
            );

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
                'unbudgeted' => (string) number_format(
                    (float) $group->sum('unbudgeted'), 2, '.', ''
                ),
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
                'expense_type' => $line->expenseCode?->expense_type,
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
