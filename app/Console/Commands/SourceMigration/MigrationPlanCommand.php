<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\MigrationPlan;
use App\Support\SourceMigration\PlanGenerator;
use App\Support\SourceMigration\SchemaInspector;
use Illuminate\Console\Command;

/**
 * Generate or validate the source-to-target migration plan (Report 51 §4).
 *
 * Generation reads the TARGET's table set (the application connection, built by
 * the full migration chain), which is also the staging table set once Stage 1 has
 * run. Nothing is written except the plan file.
 */
class MigrationPlanCommand extends Command
{
    protected $signature = 'migration:plan
        {--generate : Write a new plan from config/source_migration.php}
        {--path= : Plan file (default: config source_migration.plan_path)}
        {--force : Overwrite an existing plan file}';

    protected $description = 'Generate or validate the source-to-target migration plan';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: config('source_migration.plan_path'));

        if ($this->option('generate')) {
            if (is_file($path) && ! $this->option('force')) {
                $this->error("{$path} exists. It may carry review edits; pass --force to overwrite it.");

                return self::FAILURE;
            }
            $plan = app(PlanGenerator::class)->generate((new SchemaInspector((string) config('database.default')))->tables());
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->info("Plan written: {$path} (".count($plan->tables).' tables). Review it before any execution.');
        }

        $plan = MigrationPlan::load($path);
        $this->table(['Mode', 'Tables'], collect(MigrationPlan::MODES)->map(fn ($m) => [$m, count($plan->tablesIn($m))])->all());

        $problems = $plan->problems();
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems === []) {
            $this->info('Plan is structurally valid.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
