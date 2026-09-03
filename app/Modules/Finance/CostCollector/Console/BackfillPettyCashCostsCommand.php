<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records project costs for petty cash disbursements already paid.
 *
 * `--dry-run` executes the real backfill inside a transaction and rolls it back,
 * so the rehearsal exercises the same code path as the real thing.
 */
class BackfillPettyCashCostsCommand extends Command
{
    protected $signature = 'finance:backfill-petty-cash {--dry-run : Roll back instead of committing}';

    protected $description = 'Record project cost lines for paid petty cash disbursements';

    public function handle(PettyCashCostProducer $producer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — everything below is rolled back.');
        }

        DB::beginTransaction();

        try {
            $tally = $producer->backfill();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("Backfill aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            ['disbursements examined', number_format($tally['examined'])],
            ['posted as project cost', number_format($tally['posted'])],
            ['skipped — no job number', number_format($tally['skipped_no_job'])],
            ['skipped — job matches no project', number_format($tally['skipped_unmatched'])],
            ['skipped — voided or archived', number_format($tally['skipped_inactive'])],
        ]);

        $this->info($dryRun ? 'Rolled back.' : 'Committed.');

        return self::SUCCESS;
    }
}
