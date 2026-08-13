<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The verification queue, as one query definition.
 *
 * Both the page of rows and the figures above it are built from this, which is
 * the point. The header used to count every open cost in the system while the
 * table below showed a filtered subset, so "42 awaiting" sat above eleven rows
 * and neither number explained the other. Aggregates that do not share the
 * list's filters are not a summary of anything.
 *
 * Ageing is measured from `incurred_at`. A cost keyed in three weeks after it
 * was spent is already three weeks old — dating the queue from capture would
 * hide exactly the delay it exists to expose.
 */
class CostQueueQuery
{
    /** Days. The boundary between "current" and "someone should look". */
    public const AGE_WATCH = 7;

    /** Days. Past this a cost is late enough to be a control failure. */
    public const AGE_LATE = 30;

    public const SORTABLE = [
        'age' => 'incurred_at',
        'incurred_at' => 'incurred_at',
        'amount' => 'net_amount',
        'ref' => 'ref',
        'submitted' => 'created_at',
    ];

    /** The page of rows: the filtered set, with everything a verifier reads. */
    public function build(array $filters): Builder
    {
        return $this->applySort(
            $this->base($filters)
                ->withReferenceNames()
                ->with(['expenseCode', 'submittedBy', 'verifiedBy']),
            $filters,
        );
    }

    /**
     * The filtered set and nothing else.
     *
     * Kept separate from `build()` because the aggregates below replace the
     * SELECT with a COUNT/SUM, and the reference-name subselects `build()` adds
     * would then sit alongside an aggregate with no GROUP BY to justify them.
     *
     * @param array<string, mixed> $filters
     */
    private function base(array $filters): Builder
    {
        $query = CostLine::query()->where('nature', '!=', CostLine::NATURE_PLANNED);

        $this->applyStatus($query, $filters);
        $this->applyScope($query, $filters);
        $this->applyMoney($query, $filters);
        $this->applyDates($query, $filters);
        $this->applySearch($query, $filters);

        return $query;
    }

    /**
     * The figures above the table, over the same filters and every page of it.
     *
     * Three queries rather than one: a single grouped statement would have to
     * pivot the ageing buckets in SQL and still not give the distinct submitter
     * count. At queue sizes this is cheap and the intent stays readable.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = fn () => $this->base($filters);

        $totals = $base()
            ->selectRaw('COUNT(*) AS line_count, COALESCE(SUM(net_amount), 0) AS value')
            ->first();

        $unbudgeted = $base()
            ->whereNull('consumes_line_id')
            ->selectRaw('COUNT(*) AS line_count, COALESCE(SUM(net_amount), 0) AS value')
            ->first();

        return [
            'count' => (int) ($totals->line_count ?? 0),
            'value' => $this->money($totals->value ?? 0),
            'unbudgeted_count' => (int) ($unbudgeted->line_count ?? 0),
            'unbudgeted_value' => $this->money($unbudgeted->value ?? 0),
            'ageing' => $this->ageing($filters),
            'submitters' => $base()->distinct()->count('submitted_by_user_id'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function ageing(array $filters): array
    {
        $buckets = [
            ['id' => 'current', 'label' => 'Under a week', 'min' => null, 'max' => self::AGE_WATCH],
            ['id' => 'watch', 'label' => '1–4 weeks', 'min' => self::AGE_WATCH, 'max' => self::AGE_LATE],
            ['id' => 'late', 'label' => 'Over a month', 'min' => self::AGE_LATE, 'max' => null],
        ];

        // Computed over every other filter but NOT over the age filter itself.
        // The bar doubles as the control that sets it, so intersecting it with
        // its own selection would zero the two buckets you are not looking at
        // and leave nothing to click back to.
        $unaged = Arr::except($filters, ['age_bucket']);

        return collect($buckets)->map(function (array $bucket) use ($unaged, $filters) {
            $row = $this->applyAgeBucket($this->base($unaged), $bucket['id'])
                ->selectRaw('COUNT(*) AS line_count, COALESCE(SUM(net_amount), 0) AS value')
                ->first();

            return [
                'id' => $bucket['id'],
                'label' => $bucket['label'],
                'count' => (int) ($row->line_count ?? 0),
                'value' => $this->money($row->value ?? 0),
                'active' => ($filters['age_bucket'] ?? null) === $bucket['id'],
            ];
        })->all();
    }

    /** @param array<string, mixed> $filters */
    private function applyStatus(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        // "open" is the default and the working state: submitted plus queried,
        // because a queried cost is still the verifier's problem until the
        // reporter answers. `all` exists so rejected and reversed lines are
        // reachable at all — they previously had no screen anywhere.
        match ($status) {
            null, '', 'open' => $query->whereIn('status', [CostLine::STATUS_SUBMITTED, CostLine::STATUS_QUERIED]),
            'all' => null,
            default => $query->where('status', $status),
        };
    }

    /** @param array<string, mixed> $filters */
    private function applyScope(Builder $query, array $filters): void
    {
        $query
            ->when($filters['job_number'] ?? null, fn ($q, $job) => $q->where('job_number', $job))
            ->when($filters['enquiry_id'] ?? null, fn ($q, $id) => $q->where('project_enquiry_id', $id))
            ->when($filters['cost_centre_id'] ?? null, fn ($q, $id) => $q->where('cost_centre_id', $id))
            ->when($filters['submitted_by'] ?? null, fn ($q, $id) => $q->where('submitted_by_user_id', $id))
            ->when($filters['currency'] ?? null, fn ($q, $code) => $q->where('currency', $code))
            ->when(
                $filters['expense_code'] ?? null,
                fn ($q, $code) => $q->whereHas('expenseCode', fn ($c) => $c->where('code', $code)),
            )
            ->when(
                $filters['expense_family'] ?? null,
                fn ($q, $family) => $q->whereHas('expenseCode', fn ($c) => $c->where('expense_family', $family)),
            );

        if (($filters['unbudgeted'] ?? false) === true) {
            $query->whereNull('consumes_line_id');
        }

        // Three-valued on purpose. Absent means "do not narrow"; false is a real
        // filter — "show me only the ones with nothing attached" is how a
        // verifier finds what to chase before it ages out.
        if (array_key_exists('has_evidence', $filters) && $filters['has_evidence'] !== null) {
            $filters['has_evidence']
                ? $query->whereRaw("JSON_LENGTH(COALESCE(evidence, JSON_ARRAY())) > 0")
                : $query->whereRaw("JSON_LENGTH(COALESCE(evidence, JSON_ARRAY())) = 0");
        }

        // Origin is derived from `source_type`, so it is matched as a prefix
        // rather than stored as a column. `captured` is the absence of a
        // producer — a human stood somewhere and typed it in.
        match ($filters['origin'] ?? null) {
            'captured' => $query->whereNull('source_type'),
            'petty_cash' => $query->where('source_type', 'like', '%PettyCash%'),
            'procurement' => $query->where('source_type', 'like', '%Procurement%'),
            'stores' => $query->where('source_type', 'like', '%Stores%'),
            default => null,
        };
    }

    /** @param array<string, mixed> $filters */
    private function applyMoney(Builder $query, array $filters): void
    {
        $query
            ->when($filters['min_amount'] ?? null, fn ($q, $min) => $q->where('net_amount', '>=', $min))
            ->when($filters['max_amount'] ?? null, fn ($q, $max) => $q->where('net_amount', '<=', $max));
    }

    /** @param array<string, mixed> $filters */
    private function applyDates(Builder $query, array $filters): void
    {
        $query
            ->when(
                $filters['from'] ?? null,
                fn ($q, $from) => $q->where('incurred_at', '>=', Carbon::parse($from)->startOfDay()),
            )
            ->when(
                $filters['to'] ?? null,
                fn ($q, $to) => $q->where('incurred_at', '<=', Carbon::parse($to)->endOfDay()),
            );

        $this->applyAgeBucket($query, $filters['age_bucket'] ?? null);
    }

    /**
     * Older than N days means incurred on or before the day N days ago.
     */
    private function applyAgeBucket(Builder $query, ?string $bucket): Builder
    {
        $cutoff = fn (int $days) => now()->startOfDay()->subDays($days);

        return match ($bucket) {
            'current' => $query->where('incurred_at', '>', $cutoff(self::AGE_WATCH)),
            'watch' => $query
                ->where('incurred_at', '<=', $cutoff(self::AGE_WATCH))
                ->where('incurred_at', '>', $cutoff(self::AGE_LATE)),
            'late' => $query->where('incurred_at', '<=', $cutoff(self::AGE_LATE)),
            default => $query,
        };
    }

    /** @param array<string, mixed> $filters */
    private function applySearch(Builder $query, array $filters): void
    {
        $term = trim((string) ($filters['q'] ?? ''));

        if ($term === '') {
            return;
        }

        $like = '%' . $term . '%';

        $query->where(fn ($q) => $q
            ->where('ref', 'like', $like)
            ->orWhere('description', 'like', $like)
            ->orWhere('job_number', 'like', $like)
            ->orWhere('payee_name', 'like', $like)
            ->orWhere('submitted_by_name', 'like', $like));
    }

    /** @param array<string, mixed> $filters */
    private function applySort(Builder $query, array $filters): Builder
    {
        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? null;

        // Oldest first by default, because the risk in cost verification is a
        // backlog ageing quietly rather than a single large item. `id` breaks
        // ties so pagination cannot repeat or skip a row between pages.
        if ($column === null) {
            return $query->orderBy('incurred_at')->orderBy('id');
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($column, $direction)->orderBy('id');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
