<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Contracts\PlannedLine;
use App\Modules\Finance\CostCollector\Models\CostLine;
use Illuminate\Support\Collection;

/**
 * Projects an approved budget into `planned` cost lines.
 *
 * The budget stays where it is — the JSON on task_budget_data remains the
 * editing surface the budget screen already works against. This mirrors it into
 * rows so that variance is a GROUP BY instead of a PHP loop over decoded JSON,
 * and so an actual can point at the exact line it consumes.
 *
 * Shapes handled, verified against all 482 stored budgets:
 *   materials  nested — element → materials[], inner lines carry `is_included`
 *              and unitPrice/totalPrice
 *   labour     flat — unit, quantity, days, unitRate, amount
 *   expenses   flat — amount only, no quantity or rate
 *   logistics  flat — vehicleReg, unit, quantity, unitRate, amount
 *
 * Re-running is safe: each line is keyed by its own JSON id, so projection is
 * idempotent and a budget revised after projection converges rather than
 * duplicating.
 */
class BudgetProjector
{
    public function __construct(
        private CostCollectorService $collector,
    ) {}

    /**
     * @return array{projected:int, skipped:int, retired:int}
     */
    public function project(TaskBudgetData $budget): array
    {
        $task = $budget->task;

        if (! $task) {
            return ['projected' => 0, 'skipped' => 0, 'retired' => 0];
        }

        $lines = $this->linesFor($budget, $task->project_enquiry_id, $task->id);

        $projected = 0;
        $skipped = 0;

        foreach ($lines as $line) {
            if (bccomp($line->amount, '0', 2) !== 1) {
                $skipped++;   // zero-priced placeholder rows are not budget
                continue;
            }

            $this->collector->postPlanned($line);
            $projected++;
        }

        return [
            'projected' => $projected,
            'skipped' => $skipped,
            'retired' => $this->retireRemovedLines($budget, $lines),
        ];
    }

    /** @return array{budgets:int, projected:int, skipped:int, retired:int} */
    public function projectAll(): array
    {
        $totals = ['budgets' => 0, 'projected' => 0, 'skipped' => 0, 'retired' => 0];

        TaskBudgetData::with('task')->chunkById(100, function ($budgets) use (&$totals) {
            foreach ($budgets as $budget) {
                $result = $this->project($budget);

                $totals['budgets']++;
                $totals['projected'] += $result['projected'];
                $totals['skipped'] += $result['skipped'];
                $totals['retired'] += $result['retired'];
            }
        });

        return $totals;
    }

    /** @return Collection<int, PlannedLine> */
    private function linesFor(TaskBudgetData $budget, ?int $enquiryId, int $taskId): Collection
    {
        return collect()
            ->concat($this->materialLines($budget, $enquiryId, $taskId))
            ->concat($this->flatLines($budget, 'labour', $budget->labour_data, $enquiryId, $taskId))
            ->concat($this->flatLines($budget, 'expenses', $budget->expenses_data, $enquiryId, $taskId))
            ->concat($this->flatLines($budget, 'logistics', $budget->logistics_data, $enquiryId, $taskId));
    }

    /**
     * Materials are two levels deep: an element carries the materials that build
     * it. The element is a grouping, not a cost — only the inner lines are, and
     * only those the budget still includes.
     *
     * @return Collection<int, PlannedLine>
     */
    private function materialLines(TaskBudgetData $budget, ?int $enquiryId, int $taskId): Collection
    {
        return collect($budget->materials_data ?? [])
            ->flatMap(function ($element) {
                $elementName = is_array($element) ? ($element['name'] ?? null) : null;

                return collect($element['materials'] ?? [])
                    ->map(fn ($material) => [$material, $elementName]);
            })
            ->filter(function ($pair) {
                [$material] = $pair;

                return is_array($material)
                    && filled($material['id'] ?? null)
                    && $this->isIncluded($material);
            })
            ->map(function ($pair) use ($budget, $enquiryId, $taskId) {
                [$material, $elementName] = $pair;

                $quantity = $this->decimal($material['quantity'] ?? null);
                $rate = $this->decimal($material['unitPrice'] ?? null);

                return new PlannedLine(
                    category: 'materials',
                    amount: $this->amountOf($material, 'totalPrice', $quantity, $rate),
                    description: $this->describe($elementName, $material['description'] ?? null),
                    enquiryId: $enquiryId,
                    taskId: $taskId,
                    unit: $material['unitOfMeasurement'] ?? null,
                    quantity: $quantity,
                    unitRate: $rate,
                    sourceId: $budget->id,
                    sourceRef: (string) $material['id'],
                    isAddition: (bool) ($material['isAddition'] ?? false),
                    details: array_filter([
                        'library_material_id' => $material['libraryMaterialId'] ?? $material['library_material_id'] ?? null,
                        'project_material_id' => $material['persistent_id'] ?? $material['persistentId'] ?? $material['id'] ?? null,
                        // The element the material builds, kept as its own fact
                        // rather than only as a prefix on the description. Cost
                        // is read per element — "what did the stage cost?" — and
                        // a name buried in free text cannot be grouped on.
                        // Every downstream producer inherits it from here, the
                        // same way `budget_category` travels.
                        'element' => $elementName,
                        // The material on its own, without the element prefix the
                        // description carries for readability. Stored for the
                        // same reason the element is: the account is read down to
                        // "which material", and a name inside a sentence cannot
                        // be grouped or totalled.
                        'material' => $material['description'] ?? null,
                    ], fn ($value) => $value !== null && $value !== ''),
                );
            })
            ->values();
    }

    /**
     * Labour, expenses and logistics share one flat shape. Expenses carry only an
     * amount; the other two add unit/quantity/rate.
     *
     * @return Collection<int, PlannedLine>
     */
    private function flatLines(
        TaskBudgetData $budget,
        string $category,
        ?array $rows,
        ?int $enquiryId,
        int $taskId,
    ): Collection {
        return collect($rows ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['id'] ?? null))
            ->map(function ($row) use ($budget, $category, $enquiryId, $taskId) {
                $quantity = $this->decimal($row['quantity'] ?? null);
                $rate = $this->decimal($row['unitRate'] ?? null);

                return new PlannedLine(
                    category: $category,
                    amount: $this->amountOf($row, 'amount', $quantity, $rate),
                    description: $this->describe(
                        $row['vehicleReg'] ?? null,
                        $row['description'] ?? $row['type'] ?? null,
                    ),
                    enquiryId: $enquiryId,
                    taskId: $taskId,
                    unit: $row['unit'] ?? null,
                    quantity: $quantity,
                    unitRate: $rate,
                    sourceId: $budget->id,
                    sourceRef: (string) $row['id'],
                    isAddition: (bool) ($row['isAddition'] ?? false),
                );
            })
            ->values();
    }

    /**
     * A line deleted from the budget after projection must not keep counting.
     * Lines that something has already consumed are left alone and reported
     * instead — reversing one would orphan a real cost, which is a decision for a
     * human rather than a sync job.
     *
     * @param Collection<int, PlannedLine> $current
     */
    private function retireRemovedLines(TaskBudgetData $budget, Collection $current): int
    {
        $liveRefs = $current->pluck('sourceRef')->all();

        $orphans = CostLine::where('source_type', 'BudgetLine')
            ->where('source_id', $budget->id)
            ->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->whereNotIn('source_ref', $liveRefs ?: [''])
            ->whereDoesntHave('consumers')
            ->get();

        foreach ($orphans as $orphan) {
            $orphan->update([
                'status' => CostLine::STATUS_REVERSED,
                'query_note' => 'Removed from the budget after projection.',
            ]);
        }

        return $orphans->count();
    }

    /**
     * The exclusion flag is spelled both ways in stored data — `is_included` in
     * 178 budgets and `isIncluded` in 259 — because the budget screen changed
     * key style at some point and old rows were never rewritten. Checking only
     * one spelling would silently project excluded materials as budget on the
     * majority of records.
     *
     * Absent means included: exclusion has to be explicit.
     */
    private function isIncluded(array $material): bool
    {
        foreach (['is_included', 'isIncluded'] as $key) {
            if (array_key_exists($key, $material) && $material[$key] === false) {
                return false;
            }
        }

        return true;
    }

    private function describe(?string $prefix, ?string $detail): ?string
    {
        $parts = array_filter([trim((string) $prefix), trim((string) $detail)]);

        return $parts ? implode(' — ', array_unique($parts)) : null;
    }

    /** Trust the stored total; fall back to quantity × rate only when absent. */
    private function amountOf(array $row, string $key, ?string $quantity, ?string $rate): string
    {
        if (is_numeric($row[$key] ?? null)) {
            return number_format((float) $row[$key], 2, '.', '');
        }

        if ($quantity !== null && $rate !== null) {
            return bcmul($quantity, $rate, 2);
        }

        return '0.00';
    }

    private function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }
}
