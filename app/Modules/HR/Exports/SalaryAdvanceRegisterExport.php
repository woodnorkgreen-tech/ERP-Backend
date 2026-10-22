<?php

namespace App\Modules\HR\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

/**
 * The salary-advance workbook HR hands to Finance.
 *
 *  1. For disbursement — approved, unpaid advances with everything needed to
 *     pay them, plus blank columns for Finance to record the payout.
 *  2. Register — every filtered application, its decision, payout and recovery.
 *  3. Summary — the filters used, who generated it, totals by stage and department.
 */
/* Totals are formulas (they follow Excel filters); their values are calculated
 * before saving, so every viewer shows the real total, never 0. */
class SalaryAdvanceRegisterExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  SalaryAdvanceRegister::rows()
     * @param  array<int, string>  $filterLines  human-readable filters applied
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly array $filterLines,
        private readonly string $generatedBy,
        private readonly string $generatedAt,
        private readonly ?string $company = null,
    ) {}

    public function sheets(): array
    {
        return [$this->disbursementSheet(), $this->registerSheet(), $this->summarySheet()];
    }

    private function heading(string $title): array
    {
        return array_merge(
            [($this->company ?? config('brand.name', config('app.name'))).' — '.$title],
            ['Filters: '.($this->filterLines ? implode(' · ', $this->filterLines) : 'none (all applications)')],
            ["Generated {$this->generatedAt} by {$this->generatedBy}"],
        );
    }

    private function disbursementSheet(): SalaryAdvanceSheet
    {
        $due = $this->rows->where('stage_key', 'approved')->values()->map(fn (array $row) => array_merge($row, [
            'payment_method' => match ($row['payment_method']) {
                'mobile_money' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque', 'cash' => 'Cash', default => $row['payment_method'],
            },
            'narration' => "Salary advance {$row['reference']} — recover from {$row['target_month']} payroll",
            'finance_paid_on' => null, 'finance_reference' => null, 'finance_paid_by' => null,
        ]))->all();

        return new SalaryAdvanceSheet(
            'For disbursement',
            array_merge($this->heading('Salary advances for disbursement'), ['Approved advances not yet paid. Finance: fill the three shaded columns as each is paid.']),
            [
                ['reference', 'Ref', 'text', 11], ['staff_no', 'Staff No.', 'text', 11], ['employee', 'Employee', 'text', 24],
                ['amount', 'Amount (KES)', 'money', 14], ['payment_method', 'Pay via', 'text', 9],
                ['bank_name', 'Bank', 'text', 18], ['bank_code', 'Bank code', 'text', 9], ['bank_branch', 'Branch', 'text', 16],
                ['account_number', 'Account No.', 'text', 18], ['phone', 'Phone', 'text', 14],
                ['checks', 'Check before paying', 'text', 26], ['decided_by', 'Approved by', 'text', 18],
                ['narration', 'Narration', 'text', 36],
                ['finance_paid_on', 'Paid on', 'date', 12], ['finance_reference', 'Payment ref.', 'text', 16], ['finance_paid_by', 'Paid by', 'text', 16],
            ],
            $due,
            ['amount'],
            fn (array $row) => $row['checks'] ? 'FDE2E2' : null,
            'No approved advances are waiting for payout under these filters.',
            ['finance_paid_on', 'finance_reference', 'finance_paid_by'],
        );
    }

    private function registerSheet(): SalaryAdvanceSheet
    {
        return new SalaryAdvanceSheet(
            'Register',
            $this->heading('Salary advance register'),
            [
                ['reference', 'Ref', 'text', 11], ['submitted_on', 'Submitted', 'date', 12],
                ['staff_no', 'Staff No.', 'text', 11], ['employee', 'Employee', 'text', 24], ['department', 'Department', 'text', 18],
                ['amount', 'Advance (KES)', 'money', 14], ['target_month', 'Recover from', 'text', 11],
                ['repayment', 'Repayment plan', 'text', 26], ['reason', 'Reason', 'text', 34], ['stage', 'Status', 'text', 22],
                ['decided_by', 'Decided by', 'text', 18], ['paid_on', 'Paid on', 'date', 12], ['payment_reference', 'Payment ref.', 'text', 16],
                ['recovered', 'Recovered', 'money', 13], ['outstanding', 'Outstanding', 'money', 13],
            ],
            $this->rows->values()->all(),
            ['amount', 'recovered', 'outstanding'],
            fn (array $row) => match ($row['stage_key']) {
                'approved' => 'FEF3C7', 'rejected' => 'F1F5F9', default => null,
            },
            'No salary advance applications match these filters.',
        );
    }

    private function summarySheet(): SalaryAdvanceSheet
    {
        $byStage = $this->rows->groupBy('stage')->map(fn (Collection $group, string $stage) => [
            'group' => 'Status', 'label' => $stage, 'count' => $group->count(),
            'amount' => $group->sum('amount'), 'recovered' => $group->sum('recovered'), 'outstanding' => $group->sum(fn ($row) => (float) ($row['outstanding'] ?? 0)),
        ]);
        $byDepartment = $this->rows->groupBy(fn ($row) => $row['department'] ?: 'No department')->map(fn (Collection $group, string $department) => [
            'group' => 'Department', 'label' => $department, 'count' => $group->count(),
            'amount' => $group->sum('amount'), 'recovered' => $group->sum('recovered'), 'outstanding' => $group->sum(fn ($row) => (float) ($row['outstanding'] ?? 0)),
        ])->sortByDesc('amount');

        return new SalaryAdvanceSheet(
            'Summary',
            $this->heading('Salary advance summary'),
            [
                ['group', 'By', 'text', 13], ['label', 'Group', 'text', 30], ['count', 'Applications', 'number', 13],
                ['amount', 'Advanced (KES)', 'money', 16], ['recovered', 'Recovered', 'money', 15], ['outstanding', 'Outstanding', 'money', 15],
            ],
            $byStage->values()->concat($byDepartment->values())->all(),
            [],
            fn (array $row) => $row['group'] === 'Status' ? 'EEF2FF' : null,
            'Nothing to summarise.',
        );
    }
}
