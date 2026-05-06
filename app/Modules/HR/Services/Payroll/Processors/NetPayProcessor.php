<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;

class NetPayProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        // Net Pay = Gross Pay - Total Deductions (Custom Ledgers + Statutories)
        // Note: StatutoryProcessor already added statutory deductions to totalDeductions.
        $dto->netPay = max(0, round($dto->grossPay - $dto->totalDeductions, 2));
    }
}
