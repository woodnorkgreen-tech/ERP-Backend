<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;

class LedgerProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        $additions = 0;
        $deductions = 0;
        $details = [];

        foreach ($dto->ledgers as $ledger) {
            $amount = 0;
            if ($ledger->amount_type === 'percentage_of_basic') {
                $amount = ($ledger->amount_value / 100) * $dto->computedBasic;
            } else {
                $amount = (float)$ledger->amount_value;
            }

            if ($ledger->type === 'addition') {
                $additions += $amount;
            } else {
                $deductions += $amount;
            }

            $details[] = [
                'name' => $ledger->name,
                'type' => $ledger->type,
                'amount' => round($amount, 2)
            ];
        }

        $dto->totalAdditions = $additions;
        $dto->totalDeductions = $deductions;
        $dto->ledgerDetails = $details;
        $dto->grossPay = $dto->computedBasic + $additions;
    }
}
