<?php

namespace App\Modules\HR\Services\Payroll;

use App\Modules\HR\Services\Payroll\Processors\BasicPayProcessor;
use App\Modules\HR\Services\Payroll\Processors\LedgerProcessor;
use App\Modules\HR\Services\Payroll\Processors\StatutoryProcessor;
use App\Modules\HR\Services\Payroll\Processors\NetPayProcessor;

class CalculationPipeline
{
    protected array $processors = [];

    public function __construct()
    {
        // Define the default order of processing
        $this->processors = [
            new BasicPayProcessor(),
            new LedgerProcessor(),
            new StatutoryProcessor(),
            new NetPayProcessor(),
        ];
    }

    /**
     * Run the DTO through the pipeline.
     */
    public function calculate(PayrollEmployeeDTO $dto): void
    {
        foreach ($this->processors as $processor) {
            $processor->process($dto);
        }
    }
}
