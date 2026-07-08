<?php

namespace App\Modules\HR\Services\Payroll\Processors;

use App\Modules\HR\Services\Payroll\PayrollEmployeeDTO;
use Carbon\Carbon;

class BasicPayProcessor implements PayrollProcessorInterface
{
    public function process(PayrollEmployeeDTO $dto): void
    {
        $month       = Carbon::parse($dto->month . '-01');
        $daysInMonth = $month->daysInMonth;
        $monthStart  = $month->copy()->startOfDay();
        $monthEnd    = $month->copy()->endOfMonth()->startOfDay();

        // Clamp to the employee's active window within this month.
        // hire_date within the month → they weren't here for earlier days.
        // termination_date within the month → they left before month end.
        // Denominator always stays $daysInMonth so the daily rate is correct.
        $effectiveFrom = ($dto->hireDate && $dto->hireDate->gt($monthStart))
            ? (int) $dto->hireDate->format('j')
            : 1;

        $effectiveTo = ($dto->terminationDate && $dto->terminationDate->lt($monthEnd))
            ? (int) $dto->terminationDate->format('j')
            : $daysInMonth;

        $effectiveDays = max(0, $effectiveTo - $effectiveFrom + 1);

        if ($dto->salaryHistory->isEmpty()) {
            $dto->computedBasic = round($dto->baseSalary / $daysInMonth * $effectiveDays, 2);
            return;
        }

        $totalComputed = 0;

        for ($day = $effectiveFrom; $day <= $effectiveTo; $day++) {
            $currentDate = $month->copy()->day($day);

            $activeSalary = $dto->salaryHistory->first(function ($history) use ($currentDate) {
                return $currentDate->between(
                    Carbon::parse($history->valid_from),
                    $history->valid_to ? Carbon::parse($history->valid_to) : Carbon::now()->addYears(100)
                );
            });

            $dailySalary    = ($activeSalary ? (float) $activeSalary->salary : $dto->baseSalary) / $daysInMonth;
            $totalComputed += $dailySalary;
        }

        $dto->computedBasic = round($totalComputed, 2);
    }
}
