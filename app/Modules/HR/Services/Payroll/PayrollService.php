<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\PayrollTaxBand;
use App\Modules\HR\Models\Payslip;
use Illuminate\Support\Facades\DB;

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

        return DB::transaction(function () use ($dto, $run, $employee) {
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
                    'tax_breakdown' => $dto->taxBreakdown,
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
        $stats = $run->payslips()
            ->selectRaw('SUM(gross_pay) as total_gross, SUM(net_pay) as total_net')
            ->first();

        $run->update([
            'total_gross' => $stats->total_gross ?? 0,
            'total_net' => $stats->total_net ?? 0,
            'status' => 'locked'
        ]);
    }
}
