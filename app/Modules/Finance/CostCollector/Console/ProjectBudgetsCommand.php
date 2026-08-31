<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Projects approved budgets into planned cost lines.
 *
 * `--dry-run` executes the real projection inside a transaction and rolls it
 * back, so the rehearsal exercises the same code path as the real thing rather
 * than a simplified imitation of it — the only difference is the commit.
 */
class ProjectBudgetsCommand extends Command
{
    protected $signature = 'finance:project-budgets
                            {--budget= : Project a single task_budget_data id}
                            {--dry-run : Roll back instead of committing}';

    protected $description = 'Project approved budgets into planned cost lines';

    public function handle(BudgetProjector $projector): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — everything below is rolled back.');
        }

        DB::beginTransaction();

        try {
            $totals = $this->option('budget')
                ? $this->projectOne($projector, (int) $this->option('budget'))
                : $projector->projectAll();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("Projection aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($totals)->map(
            fn ($value, $key) => [str_replace('_', ' ', $key), number_format($value)]
        )->values());

        $this->info($dryRun ? 'Rolled back.' : 'Committed.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function projectOne(BudgetProjector $projector, int $budgetId): array
    {
        $budget = TaskBudgetData::with('task')->findOrFail($budgetId);

        return ['budgets' => 1, ...$projector->project($budget)];
    }
}
