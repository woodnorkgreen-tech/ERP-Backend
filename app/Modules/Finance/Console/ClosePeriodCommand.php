<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Services\TaxScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Month-end close, with the checks that make closing mean something.
 *
 * Every one of the 36 seeded periods has been `open` since the day they were
 * seeded, so `CostVerificationService::assertPeriodOpen()` has never once
 * fired and a cost could be backdated into January 2024 this afternoon. A
 * period that is never closed is not a control, it is a column.
 *
 * Closing is a command rather than a migration or a schedule on purpose. It is
 * a Finance decision about a month they have finished reviewing — turning it
 * into a deployment side effect, or a cron that closes last month automatically,
 * would lock periods out from under people still working in them.
 *
 * The checklist below is the point of the command. Anyone can flip a status;
 * what Finance needs is to be told what is still unfinished in the month before
 * they do.
 */
class ClosePeriodCommand extends Command
{
    protected $signature = 'finance:close-period
        {year : Calendar year, e.g. 2026}
        {month : Month number, 1-12}
        {--force : Close despite outstanding items (they are still listed)}
        {--dry-run : Run the checklist and change nothing}';

    protected $description = 'Run the month-end checklist for an accounting period and close it';

    public function handle(TaxScheduleService $schedules): int
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

        $blockers = $this->checklist($period, $schedules);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run: the period has not been changed.');

            return $blockers ? self::FAILURE : self::SUCCESS;
        }

        if ($blockers && ! $this->option('force')) {
            $this->newLine();
            $this->error('Period not closed. Resolve the items above, or re-run with --force to close anyway.');

            return self::FAILURE;
        }

        $period->forceFill([
            'status' => AccountingPeriod::STATUS_CLOSED,
            'locked_at' => now(),
            // Console runs have no authenticated user; recording nobody is more
            // honest than recording a system id that never reviewed anything.
            'locked_by' => null,
        ])->save();

        $this->newLine();
        $this->info("Closed {$period->starts_on->format('F Y')}. Costs can no longer be verified into it.");

        if ($blockers) {
            $this->warn('Closed with --force over ' . count($blockers) . ' outstanding item(s).');
        }

        return self::SUCCESS;
    }

    /**
     * What is still unfinished in this month.
     *
     * @return array<int, string>
     */
    private function checklist(AccountingPeriod $period, TaxScheduleService $schedules): array
    {
        $blockers = [];

        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        // 1. Costs still awaiting a decision. These are the real blocker: once
        //    the period is closed they cannot be verified into it at all, so
        //    closing over them silently pushes real spend into the wrong month.
        $pending = CostLine::whereIn('status', [CostLine::STATUS_SUBMITTED, CostLine::STATUS_QUERIED])
            ->whereBetween('incurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->count();

        if ($pending) {
            $blockers[] = 'pending costs';
            $this->line("  <fg=red>✗</> {$pending} cost line(s) still submitted or queried in this month.");
        } else {
            $this->line('  <fg=green>✓</> No cost lines awaiting verification.');
        }

        // 2. Verified but never journalled. A cost that reached `verified`
        //    without a journal entry is a posting failure that nothing else
        //    surfaces, and month-end is the last chance to catch it.
        $unposted = CostLine::where('status', CostLine::STATUS_VERIFIED)
            ->whereIn('nature', [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL])
            ->whereNull('posted_at')
            ->whereBetween('incurred_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->count();

        if ($unposted) {
            $blockers[] = 'unposted costs';
            $this->line("  <fg=red>✗</> {$unposted} verified cost line(s) never reached the ledger.");
        } else {
            $this->line('  <fg=green>✓</> Every verified cost in this month is journalled.');
        }

        // 3. Input VAT that cannot be claimed. Not a blocker — the month is
        //    still accounting-complete without it — but it is money, and the
        //    close meeting is where someone should see the figure.
        $gap = $schedules->vatInputSchedule($from, $to);

        if ((int) $gap['totals']['unsupported_count'] > 0) {
            $this->line(sprintf(
                '  <fg=yellow>!</> KES %s of input VAT across %d line(s) has no eTIMS reference and cannot be claimed yet.',
                number_format((float) $gap['totals']['unsupported_vat'], 2),
                $gap['totals']['unsupported_count'],
            ));
            $this->line('      Run `finance:tax etims-gap` or the eTIMS gap report before the claim window closes.');
        } else {
            $this->line('  <fg=green>✓</> All recoverable input VAT in this month is substantiated.');
        }

        // 4. What the month owes KRA, stated so the close and the remittance
        //    cannot drift apart.
        $wht = $schedules->whtSchedule($period->year, $period->month);

        $this->line(sprintf(
            '  <fg=cyan>i</> WHT to remit: KES %s across %d payee(s), due %s.',
            number_format((float) $wht['totals']['wht_remittable'], 2),
            $wht['totals']['payee_count'],
            $wht['due_date'],
        ));

        if (bccomp($wht['totals']['under_withheld'], '0.00', 2) === 1) {
            $this->line(sprintf(
                '  <fg=yellow>!</> KES %s under-withheld across %d payee(s) whose month crossed an aggregate threshold.',
                number_format((float) $wht['totals']['under_withheld'], 2),
                $wht['totals']['exposed_payee_count'],
            ));
        }

        // 5. The ledger's own arithmetic for the month.
        $sides = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.posting_date', [$from, $to])
            ->selectRaw("SUM(CASE WHEN jl.entry_type = 'debit' THEN jl.base_amount ELSE 0 END) as debit")
            ->selectRaw("SUM(CASE WHEN jl.entry_type = 'credit' THEN jl.base_amount ELSE 0 END) as credit")
            ->first();

        $debit = number_format((float) ($sides->debit ?? 0), 2, '.', '');
        $credit = number_format((float) ($sides->credit ?? 0), 2, '.', '');

        if (bccomp($debit, $credit, 2) !== 0) {
            $blockers[] = 'ledger out of balance';
            $this->line("  <fg=red>✗</> Journals for this month do not balance: debit {$debit} vs credit {$credit}.");
        } else {
            $this->line("  <fg=green>✓</> Journals balance at {$debit}.");
        }

        return $blockers;
    }
}
