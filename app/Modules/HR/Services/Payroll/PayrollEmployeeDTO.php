<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Collection;

class PayrollEmployeeDTO
{
    public function __construct(
        public int $employeeId,
        public string $name,
        public string $month,
        public float $baseSalary,
        public Collection $salaryHistory,
        public Collection $ledgers,
        public array $variables,
        public array $taxBands,
        // Statutory deduction codes this employee is exempt from (paye, nssf, shif, housing_levy)
        public array $statutoryExemptions = [],

        // Results (filled by processors)
        public float $computedBasic = 0,
        public float $totalAdditions = 0,
        public float $totalDeductions = 0,
        public float $grossPay = 0,
        public float $netPay = 0,
        // Amount of deductions that could NOT be taken because they exceeded gross
        // pay. Net is floored at 0; this records the shortfall so it is never
        // silently lost and can be carried forward / flagged for HR review.
        public float $uncoveredDeductions = 0,
        public array $taxBreakdown = [],
        public array $ledgerDetails = []
    ) {}

    /**
     * Create DTO from Employee and Month
     */
    public static function fromModel(Employee $employee, string $month, array $settings = []): self
    {
        // Compute inclusive date bounds for the payroll month
        $firstDay = $month . '-01';
        $lastDay  = date('Y-m-t', strtotime($firstDay));

        return new self(
            employeeId: $employee->id,
            name: $employee->name,
            month: $month,
            baseSalary: (float)$employee->salary,
            salaryHistory: $employee->salaryHistory()
                ->where('valid_from', '<=', $lastDay)
                ->where(function($q) use ($firstDay) {
                    // Open-ended record (still active) OR record that hasn't expired yet
                    $q->whereNull('valid_to')
                      ->orWhere('valid_to', '>=', $firstDay);
                })->get(),
            ledgers: $employee->payrollLedgers()
                ->where(function($q) use ($month) {
                    $q->where('ledger_month', $month)
                      ->orWhere('is_recurring', true);
                })->get(),
            variables: $settings['variables'] ?? [],
            taxBands: $settings['tax_bands'] ?? [],
            statutoryExemptions: $employee->statutory_exemptions ?? []
        );
    }
}
