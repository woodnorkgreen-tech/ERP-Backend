<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Support\FinanceReadiness;
use Illuminate\Console\Command;

/**
 * The last step of every deploy: fails the run when reference data is missing.
 *
 * Read-only. It reports and names the fix; it never seeds. See FinanceReadiness.
 */
class FinanceReadinessCommand extends Command
{
    protected $signature = 'finance:readiness';

    protected $description = 'Check that the reference data Finance needs is present (read-only; exits non-zero when it is not)';

    public function handle(FinanceReadiness $readiness): int
    {
        $checks = $readiness->checks();

        $this->table(['Check', 'Result', 'Detail'], array_map(
            fn (array $check) => [$check['check'], $check['ok'] ? 'PASS' : 'FAIL', $check['detail']],
            $checks,
        ));

        $failed = array_filter($checks, fn (array $check) => ! $check['ok']);
        if ($failed === []) {
            $this->info('Finance reference data is ready.');

            return self::SUCCESS;
        }

        $this->error(count($failed).' readiness check(s) failed. The app is running, but these screens will be empty or refuse work.');
        foreach (array_unique(array_column($failed, 'fix')) as $fix) {
            $this->line("  Fix: {$fix}");
        }

        return self::FAILURE;
    }
}
