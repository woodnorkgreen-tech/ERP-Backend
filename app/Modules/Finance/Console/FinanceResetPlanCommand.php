<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Support\FinanceResetBoundary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints and verifies the DATA-1 Finance reset plan. READ-ONLY: it counts rows
 * and checks the schema; it never deletes, updates, or truncates anything.
 */
class FinanceResetPlanCommand extends Command
{
    protected $signature = 'finance:reset-plan';

    protected $description = 'Show and verify the DATA-1 Finance reset plan (read-only; deletes nothing)';

    public function handle(): int
    {
        $violations = FinanceResetBoundary::violations();

        $present = FinanceResetBoundary::presentResetTables();
        $this->info('Reset order (child-first; read-only counts):');
        $this->table(['#', 'Table', 'Rows', 'Confirm on production'], collect($present)->values()->map(fn ($t, $i) => [
            $i + 1, $t, DB::table($t)->count(),
            in_array($t, FinanceResetBoundary::CONFIRM_ON_PRODUCTION, true) ? 'yes' : '',
        ]));

        $this->info('Preservation counts (must be identical before and after the reset):');
        $this->table(['Table', 'Rows'], collect(FinanceResetBoundary::PRESERVATION_COUNTS)
            ->filter(fn ($t) => DB::getSchemaBuilder()->hasTable($t))
            ->map(fn ($t) => [$t, DB::table($t)->count()]));

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }
            $this->error('The reset plan is NOT safe against this schema.');

            return self::FAILURE;
        }

        $this->info('Plan verified against this schema. Nothing was changed.');

        return self::SUCCESS;
    }
}
