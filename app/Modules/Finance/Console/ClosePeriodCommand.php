<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Services\PeriodCloseService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Month-end close from the terminal.
 *
 * The checks themselves moved to `PeriodCloseService` on 2026-09-08, when
 * Finance got a screen for this. They were private to this command, so a screen
 * would have meant a second implementation of "is this month finished" — and two
 * of those drift until they disagree about whether a month may be closed. This
 * command is now one of two callers; the API is the other.
 *
 * The command is kept rather than replaced. A terminal close is what you want
 * during a migration, a backfill or an incident, when the screen may be exactly
 * the thing that is not working.
 */
class ClosePeriodCommand extends Command
{
    protected $signature = 'finance:close-period
        {year : Calendar year, e.g. 2026}
        {month : Month number, 1-12}
        {--force : Close despite outstanding items (they are still listed)}
        {--dry-run : Run the checklist and change nothing}';

    protected $description = 'Run the month-end checklist for an accounting period and close it';

    public function handle(PeriodCloseService $periods): int
    {
        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');

        $period = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        if (! $period) {
            $this->error("No accounting period exists for {$year}-{$month}.");

            return self::FAILURE;
        }

        if (! $period->isOpen()) {
            $this->warn("The {$year}-{$month} period is already {$period->status}. Nothing to do.");

            return self::SUCCESS;
        }

        $this->info("Month-end checklist — {$period->starts_on->format('F Y')}");
        $this->newLine();

        $checklist = $periods->checklist($period);
        $this->render($checklist);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run: the period has not been changed.');

            return $checklist['blockers'] ? self::FAILURE : self::SUCCESS;
        }

        try {
            // Console runs have no authenticated user; recording nobody is more
            // honest than recording a system id that never reviewed anything.
            $result = $periods->close($period, null, (bool) $this->option('force'));
        } catch (InvalidArgumentException $exception) {
            $this->newLine();
            $this->error('Period not closed. Resolve the items above, or re-run with --force to close anyway.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Closed {$period->starts_on->format('F Y')}. Costs can no longer be verified into it.");

        if ($result['forced']) {
            $this->warn('Closed with --force over ' . count($result['blockers']) . ' outstanding item(s).');
        }

        return self::SUCCESS;
    }

    /** The service answers in data; the terminal is one of its two renderings. */
    private function render(array $checklist): void
    {
        foreach ($checklist['checks'] as $check) {
            $this->line('  ' . $this->marker($check) . ' ' . $check['message']);

            if ($check['key'] === 'unclaimable_input_vat' && ! $check['passed']) {
                $this->line('      Run the eTIMS gap report before the claim window closes.');
            }
        }
    }

    private function marker(array $check): string
    {
        if ($check['severity'] === 'info') {
            return '<fg=cyan>i</>';
        }

        if ($check['passed']) {
            return '<fg=green>✓</>';
        }

        return $check['severity'] === 'blocker' ? '<fg=red>✗</>' : '<fg=yellow>!</>';
    }
}
