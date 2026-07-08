<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\PayrollTaxBand;
use App\Modules\HR\Models\Payslip;
class PayrollService
{
    protected CalculationPipeline $pipeline;

    public function __construct()
    {
        $this->pipeline = new CalculationPipeline();
    }

    /**
     * Start or Retrieve a Payroll Run for a specific month.
     */
    public function initializeRun(string $month): PayrollRun
    {
        return PayrollRun::firstOrCreate(
            ['payroll_month' => $month, 'status' => 'draft'],
            [
                'snapshot_settings' => [
                    'variables' => PayrollVariable::all()->pluck('value', 'name')->toArray(),
                    'tax_bands' => PayrollTaxBand::all()->toArray(),
                ],
                'created_by' => auth()->id()
            ]
        );
    }

    /**
     * Calculate and Persist payroll for a single employee.
     */
    public function processEmployee(Employee $employee, PayrollRun $run): Payslip
    {
        $dto = PayrollEmployeeDTO::fromModel($employee, $run->payroll_month, $run->snapshot_settings);
        
        $this->pipeline->calculate($dto);

        // Surface any deductions that exceeded gross (net floored at 0) inside the
        // keyed tax_breakdown JSON so the shortfall is persisted and reviewable.
        $taxBreakdown = $dto->taxBreakdown;
        $taxBreakdown['uncovered_deductions'] = round($dto->uncoveredDeductions, 2);

        return DB::transaction(function () use ($dto, $run, $employee, $taxBreakdown) {
            $payslip = Payslip::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'payroll_month' => $run->payroll_month,
                ],
                [
                    'payroll_run_id' => $run->id,
                    'basic_salary' => $dto->computedBasic,
                    'gross_pay' => $dto->grossPay,
                    'net_pay' => $dto->netPay,
                    'tax_breakdown' => $taxBreakdown,
                    'ledger_breakdown' => $dto->ledgerDetails,
                    'status' => 'generated'
                ]
            );

            return $payslip;
        });
    }

    /**
     * Finalize the run and update totals.
     */
    public function finalizeRun(PayrollRun $run): void
    {
        $payslips = Payslip::where('payroll_run_id', $run->id)->get();

        $totalStatutory = $payslips->sum(function ($p) {
            $b = $p->tax_breakdown ?? [];
            return ($b['paye'] ?? 0) + ($b['nssf'] ?? 0) + ($b['shif'] ?? 0) + ($b['housing_levy'] ?? 0);
        });

        $run->update([
            'total_gross'     => $payslips->sum('gross_pay'),
            'total_net'       => $payslips->sum('net_pay'),
            'total_statutory' => $totalStatutory,
            'status'          => 'locked',
        ]);
    }
}
