<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\BudgetAddition;
use App\Modules\Finance\CostCollector\Contracts\PlannedLine;
use Illuminate\Support\Collection;

/**
 * Projects an APPROVED budget addition into `planned` cost lines.
 *
 * The baseline budget is projected by `BudgetProjector` from the JSON columns on
 * `task_budget_data`. An addition does not live there — it carries its own
 * `materials` / `labour` / `expenses` / `logistics` arrays on its own row — so
 * the baseline projector could never see it. Approval changed a status, wrote a
 * governance audit row, and stopped. Every "budget vs actual" figure therefore
 * went on using the pre-addition ceiling while the addition sat authorised in a
 * workflow of its own.
 *
 * The addition is projected as an immutable REVISION rather than by folding its
 * lines back into the baseline budget:
 *
 *   - a revision stays attributable. Which authority admitted which money is the
 *     entire reason a revision exists, and merging it into the baseline erases
 *     exactly that.
 *   - `postPlanned` already keys on `(source_type, source_id, source_ref)` and
 *     supersedes a changed line, so re-running converges instead of duplicating.
 *   - nothing is written until approval, so a rejected addition leaves no trace
 *     in the cost account — which is what "rejection creates nothing" means.
 *
 * Deliberately NOT set: the cost cause. `postPlanned` tags `isAddition` lines
 * `CLIENT-CHANGE`, whose own seed says the cost "should be billable" — but an
 * approved addition is internal authority to spend, and it may be an emergency,
 * rework, wastage or a real client variation. The record does not say which, and
 * asserting billability here is the confusion that phase 1.4 exists to resolve.
 * So revision lines are projected unclassified and honest.
 */
class BudgetRevisionProjector
{
    /** The addition's four line buckets, and the budget category each maps to. */
    private const BUCKETS = [
        'materials' => 'materials',
        'labour' => 'labour',
        'expenses' => 'expenses',
        'logistics' => 'logistics',
    ];

    public function __construct(private CostCollectorService $collector) {}

    /**
     * @return array{projected:int, skipped:int}
     */
    public function project(BudgetAddition $addition): array
    {
        // Only an approved addition is authority to spend. Anything else has no
        // business appearing in a budget figure, and this guard is what makes the
        // listener safe to replay.
        if (! $addition->isApproved()) {
            return ['projected' => 0, 'skipped' => 0];
        }

        $task = $addition->budget?->task;

        if (! $task) {
            return ['projected' => 0, 'skipped' => 0];
        }

        $projected = 0;
        $skipped = 0;

        foreach ($this->linesFor($addition, $task->project_enquiry_id, $task->id) as $line) {
            // Zero-priced rows are placeholders, not budget — the same rule the
            // baseline projector applies.
            if (bccomp($line->amount, '0', 2) !== 1) {
                $skipped++;
                continue;
            }

            $this->collector->postPlanned($line);
            $projected++;
        }

        return ['projected' => $projected, 'skipped' => $skipped];
    }

    /**
     * The addition's rows, flattened across its four buckets.
     *
     * All four are flat and share one shape — `description`, `quantity`,
     * `unit_price`, `total_price` — unlike the baseline budget, whose materials
     * are nested an element deep. That is why this is a separate projector rather
     * than a branch inside the existing one.
     *
     * @return Collection<int, PlannedLine>
     */
    private function linesFor(BudgetAddition $addition, ?int $enquiryId, int $taskId): Collection
    {
        return collect(self::BUCKETS)->flatMap(
            fn (string $category, string $bucket) => collect($addition->{$bucket} ?? [])
                ->values()
                ->map(fn ($row, $index) => $this->lineFor(
                    $addition, $category, is_array($row) ? $row : [], $index, $enquiryId, $taskId,
                ))
                ->filter(),
        );
    }

    /** @param array<string, mixed> $row */
    private function lineFor(
        BudgetAddition $addition,
        string $category,
        array $row,
        int $index,
        ?int $enquiryId,
        int $taskId,
    ): ?PlannedLine {
        $amount = $this->decimal($row['total_price'] ?? null, 2);

        if ($amount === null) {
            return null;
        }

        return new PlannedLine(
            category: $category,
            amount: $amount,
            description: $this->describe($addition, $row),
            enquiryId: $enquiryId,
            taskId: $taskId,
            unit: isset($row['unit']) ? (string) $row['unit'] : null,
            quantity: $this->decimal($row['quantity'] ?? null, 3),
            unitRate: $this->decimal($row['unit_price'] ?? null, 4),
            // The addition id is the revision's identity. The ref pins the
            // individual row inside it, by bucket and position, so editing one
            // line of a revision supersedes that line rather than the whole
            // revision — and so two revisions can never collide.
            sourceType: 'BudgetAddition',
            sourceId: (int) $addition->id,
            sourceRef: $category . ':' . $index,
            isAddition: true,
        );
    }

    /**
     * Name the line so it is findable in a budget-line picker.
     *
     * Prefixed with the revision's title because someone choosing which line a
     * cost draws down needs to know they are spending authorised extra money
     * rather than the original budget — the two are not interchangeable, even
     * when they sit in the same category.
     *
     * @param array<string, mixed> $row
     */
    private function describe(BudgetAddition $addition, array $row): ?string
    {
        $detail = isset($row['description']) ? trim((string) $row['description']) : '';
        $title = trim((string) ($addition->title ?? ''));

        $parts = array_filter([
            $title !== '' ? $title : 'Budget addition',
            $detail !== '' ? $detail : null,
        ]);

        return $parts ? implode(' — ', $parts) : null;
    }

    /** Null rather than "0" for an absent figure, so a missing quantity stays missing. */
    private function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return bcadd((string) $value, '0', $scale);
    }
}
