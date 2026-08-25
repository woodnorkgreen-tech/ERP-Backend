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
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')),
                    JSON_UNQUOTE(JSON_EXTRACT(
                        (SELECT planned.details FROM cost_lines AS planned WHERE planned.id = cost_lines.consumes_line_id),
                        '$.budget_category'
                    )),
                    CASE WHEN consumes_line_id IS NULL THEN ? ELSE 'Other project costs' END
                ) AS category,
                nature,
                SUM(net_amount) AS total,
                SUM(CASE WHEN nature <> ? AND consumes_line_id IS NULL THEN net_amount ELSE 0 END) AS unbudgeted,
                COUNT(*) AS line_count
            ", [self::CATEGORY_UNBUDGETED, CostLine::NATURE_PLANNED])
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
            'elements' => $this->elementBreakdown($enquiry),
            'unbudgeted' => $this->unbudgeted($enquiry),
            'exceptions' => $this->exceptionSpend($enquiry),
            'coverage' => $this->coverage($enquiry),
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
            ->with(['expenseCode', 'submittedBy'])
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

                if ($category === self::CATEGORY_UNBUDGETED) {
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
