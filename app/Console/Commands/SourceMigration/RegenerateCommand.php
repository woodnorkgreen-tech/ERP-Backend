<?php

namespace App\Console\Commands\SourceMigration;

use App\Constants\RolePermissions;
use App\Models\TaskBudgetData;
use App\Modules\Finance\CostCollector\Services\BudgetProjector;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\EvidenceReports;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\SchemaInspector;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/**
 * Regenerate the redesigned ERP's derived data on the TARGET after the Stage 2
 * load (Report 51 §22). Dry run by default.
 *
 *   permissions         RoleAndPermissionSeeder + permissions:sync (additive; imported
 *                       roles keep their users; obsolete permissions are not imported)
 *   reference           FinanceReferenceSeeder — target-only Finance masters. The
 *                       reference chart of accounts is forced OFF: D3 is pending
 *   planned-cost-lines  BudgetProjector for ACTIVE/OPEN projects only (D5)
 *   all                 the three, in that order
 *
 * Never generated here: GL opening balances and the petty-cash opening float (D7).
 */
class RegenerateCommand extends Command
{
    protected $signature = 'migration:regenerate
        {step : permissions|reference|planned-cost-lines|all}
        {--execute : Write. Without this flag the command is a dry run}
        {--confirm= : The target database name, typed exactly (required with --execute)}
        {--cutover : Required as well when the target is the LIVE target}';

    protected $description = 'Regenerate permissions, target reference data and D5-eligible planned CostLines (dry run by default)';

    public function handle(): int
    {
        $step = (string) $this->argument('step');
        $steps = $step === 'all' ? ['permissions', 'reference', 'planned-cost-lines'] : [$step];
        if (array_diff($steps, ['permissions', 'reference', 'planned-cost-lines']) !== []) {
            $this->error("Unknown step '{$step}'.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute) {
            try {
                ConnectionGuard::make()->assertExecutionConfirmed($this->option('confirm'), (bool) $this->option('cutover'));
            } catch (MigrationRefused $refused) {
                $this->error('REFUSED: '.$refused->getMessage());

                return self::FAILURE;
            }
        }

        foreach ($steps as $s) {
            $ok = match ($s) {
                'permissions' => $this->permissions($execute),
                'reference' => $this->reference($execute),
                'planned-cost-lines' => $this->plannedCostLines($execute),
            };
            if (! $ok) {
                return self::FAILURE;
            }
        }

        $this->line('Not generated (D7): GL opening balances and the petty-cash opening float — accountant input at cutover.');
        $this->info($execute ? 'Regeneration complete.' : 'DRY RUN — nothing was written.');

        return self::SUCCESS;
    }

    private function permissions(bool $execute): bool
    {
        $missingRoles = array_values(array_diff(array_keys(RolePermissions::matrix()), Role::query()->pluck('name')->all()));
        $this->line('Permissions: matrix roles to be created: '.($missingRoles ? implode(', ', $missingRoles) : 'none'));

        if (! $execute) {
            if ($missingRoles === []) {
                Artisan::call('permissions:sync', ['--dry-run' => true]);
                $this->output->write(Artisan::output());
            }

            return true;
        }

        (new RoleAndPermissionSeeder)->run();
        $exit = Artisan::call('permissions:sync');
        $this->output->write(Artisan::output());

        return $exit === 0;
    }

    private function reference(bool $execute): bool
    {
        $this->line('Reference: FinanceReferenceSeeder (chart of accounts seeding forced OFF — D3 pending).');
        if ($execute) {
            config(['finance_accounts.seed_reference_chart' => false]);
            $seeder = new FinanceReferenceSeeder;
            $seeder->setContainer(app())->setCommand($this);
            $seeder->run();
        }

        return true;
    }

    private function plannedCostLines(bool $execute): bool
    {
        $db = new SchemaInspector((string) config('database.default'));
        $eligible = EvidenceReports::eligibleBudgetIds($db);
        $total = $db->count('task_budget_data');
        $this->line(sprintf('Planned CostLines (D5): %d eligible budget(s) of %d; %d historical budget(s) left without redesigned planned lines.',
            count($eligible), $total, $total - count($eligible)));

        if (! $execute) {
            return true;
        }

        $projector = app(BudgetProjector::class);
        $totals = [];
        foreach (array_chunk($eligible, 100) as $ids) {
            foreach (TaskBudgetData::with('task')->whereIn('id', $ids)->orderBy('id')->get() as $budget) {
                foreach ($projector->project($budget) as $key => $value) {
                    if (is_int($value)) {
                        $totals[$key] = ($totals[$key] ?? 0) + $value;
                    }
                }
            }
        }
        $this->line('Projected: '.json_encode($totals));

        return true;
    }
}
