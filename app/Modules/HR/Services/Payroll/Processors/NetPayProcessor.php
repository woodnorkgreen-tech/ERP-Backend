<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;

class NetPayProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        // Net Pay = Gross Pay - Total Deductions (Custom Ledgers + Statutories)
        // Note: StatutoryProcessor already added statutory deductions to totalDeductions.
        $rawNet = round($dto->grossPay - $dto->totalDeductions, 2);

        // Net pay cannot go negative. When deductions exceed gross, record the
        // shortfall instead of silently discarding it, so HR can carry it forward
        // (and so this surfaces against the Employment Act two-thirds deduction cap).
        $dto->uncoveredDeductions = $rawNet < 0 ? abs($rawNet) : 0.0;
        $dto->netPay = max(0, $rawNet);
    }
}
