<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\HR\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollFinancePostingService
{
    private const SALARIES_EXPENSE = '7550';
    private const PAYE_PAYABLE = '2130';
    private const STATUTORY_PAYABLE = '2140';
    private const NET_PAYROLL_PAYABLE = '2160';

    public function postAccrual(PayrollRun $run): JournalEntry
    {
        if ($run->accrual_journal_entry_id) {
            return JournalEntry::findOrFail($run->accrual_journal_entry_id);
        }

        return DB::transaction(function () use ($run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->accrual_journal_entry_id) {
                return JournalEntry::findOrFail($run->accrual_journal_entry_id);
            }

            $postingDate = Carbon::createFromFormat('Y-m', $run->payroll_month)->endOfMonth();
            $period = $this->openPeriod($postingDate);
            $payslips = $run->payslips()->get();
            $gross = $this->money($payslips->sum('gross_pay'));
            $net = $this->money($payslips->sum('net_pay'));
            $paye = $this->money($payslips->sum(fn ($p) => (float) data_get($p->tax_breakdown, 'paye', 0)));
            $deductions = $this->money(max(0, (float) $gross - (float) $net - (float) $paye));

            if (bccomp($gross, '0.00', 2) <= 0 || bccomp($net, '0.00', 2) < 0) {
                throw new InvalidArgumentException('Payroll accrual requires positive gross pay and non-negative net pay.');
            }

            $legs = [
                [$this->account(self::SALARIES_EXPENSE), 'debit', $gross, 'Gross salaries and wages'],
                [$this->account(self::NET_PAYROLL_PAYABLE), 'credit', $net, 'Net payroll payable'],
            ];
            if (bccomp($paye, '0.00', 2) === 1) {
                $legs[] = [$this->account(self::PAYE_PAYABLE), 'credit', $paye, 'PAYE payable'];
            }
            if (bccomp($deductions, '0.00', 2) === 1) {
                $legs[] = [$this->account(self::STATUTORY_PAYABLE), 'credit', $deductions, 'Other payroll deductions payable'];
            }

            $entry = $this->journal(
                'JE-PR-A-' . str_pad((string) $run->id, 7, '0', STR_PAD_LEFT),
                $postingDate->toDateString(), $period, $run,
                "Payroll accrual for {$run->payroll_month}", $gross, $legs
            );
            $run->update(['accrual_journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    public function postPayment(PayrollRun $run, PaymentSource $source, string $date, string $reference): JournalEntry
    {
        if (! $source->is_active || ! $source->gl_account_id || $source->type === 'payable') {
            throw new InvalidArgumentException('Select an active cash, bank, card, or mobile-money payment source with a GL account.');
        }

        return DB::transaction(function () use ($run, $source, $date, $reference) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->payment_journal_entry_id) {
                return JournalEntry::findOrFail($run->payment_journal_entry_id);
            }
            if (! $run->accrual_journal_entry_id) {
                throw new InvalidArgumentException('Post the payroll accrual before recording payment.');
            }

            $postingDate = Carbon::parse($date);
            $period = $this->openPeriod($postingDate);
            $net = $this->money($run->payslips()->sum('net_pay'));
            $legs = [
                [$this->account(self::NET_PAYROLL_PAYABLE), 'debit', $net, 'Settle net payroll payable'],
                [(int) $source->gl_account_id, 'credit', $net, "Payroll payment via {$source->name}"],
            ];
            $entry = $this->journal(
                'JE-PR-P-' . str_pad((string) $run->id, 7, '0', STR_PAD_LEFT),
                $postingDate->toDateString(), $period, $run,
                "Payroll payment {$reference} for {$run->payroll_month}", $net, $legs
            );
            $run->update([
                'payment_journal_entry_id' => $entry->id,
                'payment_source_id' => $source->id,
                'payment_date' => $postingDate->toDateString(),
                'payment_reference' => $reference,
            ]);

            return $entry;
        });
    }

    private function journal(string $number, string $date, AccountingPeriod $period, PayrollRun $run, string $description, string $total, array $legs): JournalEntry
    {
        $entry = JournalEntry::create([
            'entry_no' => $number, 'posting_date' => $date,
            'accounting_period_id' => $period->id,
            'source_type' => PayrollRun::class, 'source_id' => $run->id,
            'source_ref' => "PAYROLL-{$run->payroll_month}", 'description' => $description,
            'total_debit' => $total, 'total_credit' => $total, 'status' => 'posted',
            'created_by' => auth()->id(), 'posted_at' => now(),
        ]);
        foreach ($legs as [$account, $type, $amount, $lineDescription]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id, 'account_id' => $account,
                'entry_type' => $type, 'amount' => $amount, 'base_amount' => $amount,
                'currency' => 'KES', 'fx_rate' => 1, 'description' => $lineDescription,
            ]);
        }

        return $entry;
    }

    private function account(string $code): int
    {
        $id = ChartOfAccount::postable()->where('code', $code)->value('id');
        if (! $id) {
            throw new InvalidArgumentException("Payroll GL account {$code} is not configured as postable.");
        }
        return (int) $id;
    }

    private function openPeriod(Carbon $date): AccountingPeriod
    {
        $period = AccountingPeriod::forDate($date);
        if (! $period || ! $period->isOpen()) {
            throw new InvalidArgumentException("No open accounting period contains {$date->toDateString()}.");
        }
        return $period;
    }

    private function money(float|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
