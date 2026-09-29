<?php

namespace App\Console\Commands\SourceMigration;

use App\Support\SourceMigration\ConnectionGuard;
use App\Support\SourceMigration\MigrationRefused;
use App\Support\SourceMigration\OvertimeChainVerifier;
use App\Support\SourceMigration\ReportWriter;
use Illuminate\Console\Command;

/**
 * Verify the overtime ledger hash chain on the staging copy and on the target, and
 * report any break the migration introduced (Report 51 §18). Never re-hashes.
 */
class VerifyOvertimeChainCommand extends Command
{
    protected $signature = 'migration:verify-overtime-chain
        {--target-only : Verify the application connection only (no staging comparison)}
        {--report-dir= : Report directory base}';

    protected $description = 'Verify the overtime ledger hash chain (staging vs target); read-only';

    public function handle(): int
    {
        $guard = ConnectionGuard::make();
        $writer = new ReportWriter(null, $this->option('report-dir') ?: null);
        $target = (new OvertimeChainVerifier($guard->targetConnection()))->verify();
        $this->line("Target: {$target['entries']} entries, {$target['broken_links']} broken link(s), {$target['unhashed_entries']} unhashed.");

        if ($this->option('target-only')) {
            $writer->write('overtime_chain', ['target' => $target]);

            return $target['passes'] ? self::SUCCESS : self::FAILURE;
        }

        try {
            $guard->assertDistinctDatabases();
            $guard->makeSourceReadOnly();
        } catch (MigrationRefused $refused) {
            $this->error('REFUSED: '.$refused->getMessage());

            return self::FAILURE;
        }

        $staging = (new OvertimeChainVerifier($guard->sourceConnection()))->verify();
        $introduced = OvertimeChainVerifier::introducedByMigration($staging, $target);
        $this->line("Staging: {$staging['entries']} entries, {$staging['broken_links']} broken link(s) (historical).");

        $passes = $introduced === [] && $staging['entries'] === $target['entries'];
        $writer->write('overtime_chain', ['staging' => $staging, 'target' => $target, 'breaks_introduced_by_migration' => $introduced, 'passes' => $passes]);
        $this->{$passes ? 'info' : 'error'}('Overtime chain: '.($passes ? 'PASS — no break introduced by the migration' : 'FAIL — '.count($introduced).' break(s) introduced, or entry counts differ'));

        return $passes ? self::SUCCESS : self::FAILURE;
    }
}
