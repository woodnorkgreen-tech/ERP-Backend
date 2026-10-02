<?php

namespace Tests\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use Spatie\Permission\Models\Permission;

trait CashReportingFixtures
{
    private User $reader;
    private User $outsider;

    private function prepareCashReporting(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 30)->startOfDay());
        config(['finance_accounts.map' => [], 'finance_accounts.payment_sources' => [], 'finance_accounts.seed_reference_chart' => true]);
        $this->seed(FinanceReferenceSeeder::class);
        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);
        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail();
    }

    /** Fixtures are balanced journals; actual reversal tests invoke the posting service. */
    private function cashJournal(string $date, array $legs, string $status = 'posted'): JournalEntry
    {
        $debits = $credits = '0.00';
        foreach ($legs as [$code, $side, $amount]) {
            if ($side === 'debit') {
                $debits = bcadd($debits, $amount, 2);
            } else {
                $credits = bcadd($credits, $amount, 2);
            }
        }
        $entry = JournalEntry::create([
            'entry_no' => 'JE-CASH-'.uniqid(), 'posting_date' => $date,
            'accounting_period_id' => AccountingPeriod::forDate(\Carbon\Carbon::parse($date))->id,
            'source_type' => 'CashReportingTest', 'source_id' => 0, 'source_ref' => 'CASH-FIXTURE',
            'description' => 'Cash reporting fixture', 'total_debit' => $debits, 'total_credit' => $credits,
            'status' => $status, 'posted_at' => $status === 'draft' ? null : now(),
        ]);
        foreach ($legs as [$code, $side, $amount]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id, 'account_id' => $this->account($code)->id,
                'entry_type' => $side, 'amount' => $amount, 'base_amount' => $amount, 'currency' => 'KES', 'fx_rate' => 1,
            ]);
        }
        return $entry;
    }
}
