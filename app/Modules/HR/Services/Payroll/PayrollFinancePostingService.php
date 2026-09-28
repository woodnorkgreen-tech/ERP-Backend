<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Services\PaymentSettlementService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\HR\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollFinancePostingService
{
    public function __construct(
        private JournalPostingService $posting,
        private PaymentSettlementService $settlements,
    ) {
    }

    private const SALARIES_EXPENSE = FinanceAccountFunctions::SALARIES_EXPENSE;        // office and admin staff — overhead
    private const DIRECT_LABOUR_EXPENSE = FinanceAccountFunctions::COS_DIRECT_LABOUR;   // people delivering client work — cost of sales
    private const PAYE_PAYABLE = FinanceAccountFunctions::PAYE_PAYABLE;
    private const STATUTORY_PAYABLE = FinanceAccountFunctions::STATUTORY_PAYABLE;
    private const NET_PAYROLL_PAYABLE = FinanceAccountFunctions::NET_PAYROLL_PAYABLE;

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

            /*
             * Split gross pay between delivering client work and running the
             * office, so a technician on a client's stand is not recorded like
             * an accounts clerk. Without the split, 100% of payroll sat in
             * operating expenses and gross margin could not be computed at all.
             *
             * The split is by DEPARTMENT, because that is the only attribution
             * WNG's data supports — see the migration that added
             * `departments.labour_classification`. It deliberately does not
             * reach individual jobs: nobody records hours against jobs, and an
             * invented allocation would produce job margins that look precise
             * and are fiction.
             */
            $split = $this->splitGrossByLabourClassification($payslips);

            $legs = [];

            if (bccomp($split['direct'], '0.00', 2) === 1) {
                $legs[] = [$this->account(self::DIRECT_LABOUR_EXPENSE), 'debit', $split['direct'],
                    'Direct labour — staff delivering client work'];
            }
            if (bccomp($split['indirect'], '0.00', 2) === 1) {
                $legs[] = [$this->account(self::SALARIES_EXPENSE), 'debit', $split['indirect'],
                    'Salaries and wages — office and administration'];
            }

            $legs[] = [$this->account(self::NET_PAYROLL_PAYABLE), 'credit', $net, 'Net payroll payable'];

            if (bccomp($paye, '0.00', 2) === 1) {
                $legs[] = [$this->account(self::PAYE_PAYABLE), 'credit', $paye, 'PAYE payable'];
            }
            if (bccomp($deductions, '0.00', 2) === 1) {
                $legs[] = [$this->account(self::STATUTORY_PAYABLE), 'credit', $deductions, 'Other payroll deductions payable'];
            }

            /*
             * What WNG pays ON TOP of gross — the employer's own share of the
             * National Social Security Fund and the Affordable Housing Levy.
             *
             * These were computed by StatutoryProcessor and recorded nowhere, so
             * the cost of employing people was understated by exactly this
             * figure every month. They are an expense AND a liability: WNG has
             * incurred the cost, and owes it to the Authority.
             *
             * Added as a matched debit/credit pair so the entry's existing
             * balance is undisturbed — the gross-to-net arithmetic checked below
             * stays exactly as it was.
             */
            $employer = $this->money($payslips->sum(
                fn ($p) => (float) data_get($p->tax_breakdown, 'employer_total', 0)
            ));

            if (bccomp($employer, '0.00', 2) === 1) {
                $employerSplit = $this->apportion($employer, $split);

                if (bccomp($employerSplit['direct'], '0.00', 2) === 1) {
                    $legs[] = [$this->account(self::DIRECT_LABOUR_EXPENSE), 'debit', $employerSplit['direct'],
                        "Employer statutory contributions — direct labour"];
                }
                if (bccomp($employerSplit['indirect'], '0.00', 2) === 1) {
                    $legs[] = [$this->account(self::SALARIES_EXPENSE), 'debit', $employerSplit['indirect'],
                        "Employer statutory contributions — office and administration"];
                }

                $legs[] = [$this->account(self::STATUTORY_PAYABLE), 'credit', $employer,
                    'Employer statutory contributions payable'];
            }

            $entry = $this->journal(
                'JE-PR-A-' . str_pad((string) $run->id, 7, '0', STR_PAD_LEFT),
                $postingDate->toDateString(), $period, $run,
                "Payroll accrual for {$run->payroll_month}",
                bcadd($gross, $employer, 2), $legs
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

            // Critical Risk C7 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
            // give payroll's cash-out the same independent money-movement
            // record every other payment rail already has, through the same
            // engine (PaymentSettlementService) rather than a bespoke one.
            // Forward-only: historical runs paid before this existed are not
            // backfilled here — see STAB-6/W10-1 in
            // finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md.
            $payment = $this->settlements->settle([
                'payment_no' => DocumentNumber::next(DocumentNumber::PAYMENT, (string) $postingDate->year),
                'payment_type' => 'direct',
                'payment_source_id' => $source->id,
                'payee_name' => "Payroll {$run->payroll_month}",
                'account' => 'Payroll settlement',
                'amount' => $net,
                'description' => "Net payroll for {$run->payroll_month}",
                'date_disbursed' => $postingDate->toDateString(),
                'external_reference' => $reference,
                'classification' => 'admin',
                'tax' => 'no_etr',
                'receipt_type' => 'none',
                'created_by' => auth()->id(),
                'source_document_type' => PayrollRun::class,
                'source_document_id' => $run->id,
                'idempotency_key' => 'payroll-run:'.$run->id,
            ]);

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
                'payment_id' => $payment->id,
            ]);

            return $entry;
        });
    }

    /**
     * Hand the legs to the ledger's single writer.
     *
     * This method used to create the JournalEntry and its JournalLine rows
     * itself, with its own copy of "the accounts must be postable" and "the
     * sides must agree" — and no idempotency guard at all, so a retried run
     * could produce a second entry under a duplicate number. Two writers with
     * two rule sets is exactly what produced a second, unreconciled ledger in
     * petty cash once already.
     *
     * What stays here is the check no generic writer could make: that gross pay,
     * net pay and the deductions actually reconcile against the payslips. A
     * header that balances while disagreeing with the payslips underneath it is
     * a payroll problem, and it is caught before the legs are handed over.
     */
    private function journal(string $number, string $date, AccountingPeriod $period, PayrollRun $run, string $description, string $total, array $legs): JournalEntry
    {
        $debit = '0.00';
        $credit = '0.00';
        foreach ($legs as [$account, $type, $amount]) {
            if ($type === 'debit') {
                $debit = bcadd($debit, $amount, 2);
            } else {
                $credit = bcadd($credit, $amount, 2);
            }
        }
        if (bccomp($debit, $credit, 2) !== 0 || bccomp($debit, $total, 2) !== 0) {
            throw new InvalidArgumentException('Payroll gross pay, net pay and deductions do not reconcile. Correct the payslips before posting.');
        }


        return $this->posting->postBalancedEntry(
            entryNo: $number,
            postingDate: $date,
            sourceType: PayrollRun::class,
            sourceId: $run->id,
            sourceRef: "PAYROLL-{$run->payroll_month}",
            description: $description,
            legs: array_map(fn (array $leg) => [
                'account_id' => $leg[0],
                'entry_type' => $leg[1],
                'amount' => $leg[2],
                'description' => $leg[3] ?? null,
            ], $legs),
            createdBy: auth()->id(),
        );
    }

    /**
     * Gross pay divided between delivering client work and running the office.
     *
     * Resolved per payslip through the employee's department. A department that
     * has not been classified counts as INDIRECT — which is precisely what the
     * system did before the split existed, so an unclassified installation
     * behaves exactly as it always has and classifying is an improvement
     * somebody opts into rather than a silent restatement.
     *
     * @return array{direct: string, indirect: string}
     */
    private function splitGrossByLabourClassification($payslips): array
    {
        $directEmployeeIds = DB::table('employees')
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->where('departments.labour_classification', 'direct')
            ->pluck('employees.id')
            ->flip();

        $direct = '0.00';
        $indirect = '0.00';

        foreach ($payslips as $payslip) {
            $amount = $this->money($payslip->gross_pay);

            if ($directEmployeeIds->has($payslip->employee_id)) {
                $direct = bcadd($direct, $amount, 2);
            } else {
                $indirect = bcadd($indirect, $amount, 2);
            }
        }

        return ['direct' => $direct, 'indirect' => $indirect];
    }

    /**
     * Divide one figure in the same proportion as the gross split.
     *
     * Employer contributions follow the salary that caused them, so they land in
     * the same place as the pay they are charged on. The indirect half is the
     * BALANCING figure rather than a second rounded calculation — otherwise a
     * rounding difference of a cent would unbalance the entry, and a journal
     * that will not post is a worse outcome than a cent in the wrong column.
     *
     * @return array{direct: string, indirect: string}
     */
    private function apportion(string $amount, array $split): array
    {
        $gross = bcadd($split['direct'], $split['indirect'], 2);

        if (bccomp($gross, '0.00', 2) <= 0) {
            return ['direct' => '0.00', 'indirect' => $amount];
        }

        $direct = $this->money(bcdiv(bcmul($amount, $split['direct'], 6), $gross, 6));

        return ['direct' => $direct, 'indirect' => bcsub($amount, $direct, 2)];
    }

    private function account(string $code): int
    {
        $localCode = ChartAccountMap::local($code);
        $id = ChartOfAccount::postable()->where('code', $localCode)->value('id');
        if (! $id) {
            $suffix = $localCode === $code ? '' : " (mapped from reference code {$code})";
            throw new InvalidArgumentException("Payroll GL account {$localCode}{$suffix} is not configured as postable.");
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
