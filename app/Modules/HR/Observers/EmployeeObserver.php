<?php

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use Carbon\Carbon;

class EmployeeObserver
{
    /**
     * Handle the Employee "created" event.
     */
    public function created(Employee $employee): void
    {
        // Initial salary history
        if ($employee->salary > 0) {
            EmployeeSalaryHistory::create([
                'employee_id' => $employee->id,
                'salary' => $employee->salary,
                'valid_from' => $employee->hire_date ?? now()->toDateString(),
                'created_by' => auth()->id(),
            ]);
        }
    }

    /**
     * Handle the Employee "updating" event.
     */
    public function updating(Employee $employee): void
    {
        // If salary has changed, close the old history record and create a new one
        if ($employee->isDirty('salary')) {
            $oldSalary = $employee->getOriginal('salary');
            $newSalary = $employee->salary;

            // Find the active history record (where valid_to is null)
            $activeHistory = EmployeeSalaryHistory::where('employee_id', $employee->id)
                ->whereNull('valid_to')
                ->latest('valid_from')
                ->first();

            $today = now()->toDateString();

            if ($activeHistory) {
                // If the active history started today, just update it (to avoid multiple records on same day)
                if ($activeHistory->valid_from->toDateString() === $today) {
                    $activeHistory->update(['salary' => $newSalary]);
                } else {
                    // Close the current one yesterday
                    $yesterday = now()->subDay()->toDateString();
                    $activeHistory->update(['valid_to' => $yesterday]);

                    // Create new one starting today
                    EmployeeSalaryHistory::create([
                        'employee_id' => $employee->id,
                        'salary' => $newSalary,
                        'valid_from' => $today,
                        'created_by' => auth()->id(),
                    ]);
                }
            } else {
                // No active history found, create a new one
                EmployeeSalaryHistory::create([
                    'employee_id' => $employee->id,
                    'salary' => $newSalary,
                    'valid_from' => $today,
                    'created_by' => auth()->id(),
                ]);
            }
        }
    }
}
