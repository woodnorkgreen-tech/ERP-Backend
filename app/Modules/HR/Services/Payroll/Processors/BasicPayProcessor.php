<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;
use Carbon\Carbon;

class BasicPayProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        $month = Carbon::parse($dto->month . '-01');
        $daysInMonth = $month->daysInMonth;
        
        if ($dto->salaryHistory->isEmpty()) {
            $dto->computedBasic = $dto->baseSalary;
            return;
        }

        $totalComputed = 0;
        
        // Split the month into segments based on salary history
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDate = $month->copy()->day($day);
            
            // Find the salary active on this specific day
            $activeSalary = $dto->salaryHistory->first(function($history) use ($currentDate) {
                return $currentDate->between(
                    Carbon::parse($history->valid_from),
                    $history->valid_to ? Carbon::parse($history->valid_to) : Carbon::now()->addYears(100)
                );
            });

            $dailySalary = ($activeSalary ? (float)$activeSalary->salary : $dto->baseSalary) / $daysInMonth;
            $totalComputed += $dailySalary;
        }

        $dto->computedBasic = round($totalComputed, 2);
    }
}
