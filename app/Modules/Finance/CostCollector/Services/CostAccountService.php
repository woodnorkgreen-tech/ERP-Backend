<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
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
        $aggregate = CostLine::query()
            ->counting()
            ->whereNotNull('project_enquiry_id')
            ->selectRaw('
                project_enquiry_id,
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END)   AS planned,
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END)   AS committed,
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END)   AS actual,
                SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted
            ', [
                CostLine::NATURE_PLANNED,
                CostLine::NATURE_COMMITTED,
                CostLine::NATURE_ACTUAL,
                CostLine::NATURE_PLANNED,
            ])
            ->groupBy('project_enquiry_id');

        $paginator = $aggregate->paginate(max(1, min($perPage, 100)));

        $enquiries = ProjectEnquiry::whereIn('id', collect($paginator->items())->pluck('project_enquiry_id'))
            ->get(['id', 'job_number', 'title', 'status'])
            ->keyBy('id');

        $rows = collect($paginator->items())->map(function ($row) use ($enquiries) {
            $enquiry = $enquiries->get($row->project_enquiry_id);
            $planned = $this->money($row->planned);
            $spent = bcadd($this->money($row->actual), $this->money($row->committed), 2);

            return [
                'enquiry_id' => $row->project_enquiry_id,
                'job_number' => $enquiry?->job_number,
                'title' => $enquiry?->title,
                'status' => $enquiry?->status,
                'planned' => $planned,
                'committed' => $this->money($row->committed),
                'actual' => $this->money($row->actual),
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

    /** @return array<string, string> */
    private function grandTotals(array $filters): array
    {
        $row = CostLine::query()
            ->counting()
            ->whereNotNull('project_enquiry_id')
            ->selectRaw('
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS planned,
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS committed,
                SUM(CASE WHEN nature = ? THEN net_amount ELSE 0 END) AS actual,
                SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted
            ', [
                CostLine::NATURE_PLANNED,
                CostLine::NATURE_COMMITTED,
                CostLine::NATURE_ACTUAL,
                CostLine::NATURE_PLANNED,
            ])
            ->first();

        $planned = $this->money($row?->planned);
        $spent = bcadd($this->money($row?->actual), $this->money($row?->committed), 2);

        return [
            'planned' => $planned,
            'committed' => $this->money($row?->committed),
            'actual' => $this->money($row?->actual),
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
                COUNT(*) AS line_count
            ")
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
