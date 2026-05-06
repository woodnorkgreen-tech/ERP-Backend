<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;

interface PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void;
}
