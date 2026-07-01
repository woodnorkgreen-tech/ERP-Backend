<?php

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\HRAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;

class EmployeeObserver
{
    // ── Fields whose changes are written to HRAuditLog ───────────────────────
    // Omit profile_photo_path (binary blob reference) and audit-noise fields.
    private const AUDITED_FIELDS = [
        'first_name', 'last_name', 'email', 'phone',
        'department_id', 'position', 'status', 'employment_type',
        'hire_date', 'probation_end_date', 'is_on_probation', 'contract_end_date',
        'manager_id',
        // Salary — also triggers salary-history side-effect below.
        'salary',
        // PII / statutory
        'id_number', 'kra_pin', 'nssf_id', 'nhif_id',
        'bank_name', 'bank_branch', 'bank_code', 'account_number', 'payment_method',
        'statutory_exemptions',
        'address', 'date_of_birth', 'emergency_contact',
    ];

    // ── Lifecycle hooks ──────────────────────────────────────────────────────

    /**
     * Handle the Employee "created" event.
     * Writes an audit log entry and seeds the initial salary history.
     */
    public function created(Employee $employee): void
    {
        $this->writeAuditLog($employee, 'employee_created', 'Employee record created.', [
            'snapshot' => $employee->only(self::AUDITED_FIELDS),
        ]);

        // Initial salary history
        if ($employee->salary > 0) {
            EmployeeSalaryHistory::create([
                'employee_id' => $employee->id,
                'salary'      => $employee->salary,
                'valid_from'  => $employee->hire_date ?? now()->toDateString(),
                'created_by'  => auth()->id(),
            ]);
        }
    }

    /**
     * Handle the Employee "updating" event.
     * Captures a field-level diff in HRAuditLog and, when salary changes,
     * maintains the temporal salary-history chain.
     */
    public function updating(Employee $employee): void
    {
        $dirty = $employee->getDirty();

        // Build a diff limited to audited fields.
        $diff = [];
        foreach (self::AUDITED_FIELDS as $field) {
            if (array_key_exists($field, $dirty)) {
                $diff[$field] = [
                    'from' => $employee->getOriginal($field),
                    'to'   => $employee->$field,
                ];
            }
        }

        if (! empty($diff)) {
            $this->writeAuditLog($employee, 'employee_updated', 'Employee record updated.', [
                'changes' => $diff,
            ]);
        }

        // ── Salary history side-effect ────────────────────────────────────
        if ($employee->isDirty('salary')) {
            $oldSalary = $employee->getOriginal('salary');
            $newSalary = $employee->salary;

            $activeHistory = EmployeeSalaryHistory::where('employee_id', $employee->id)
                ->whereNull('valid_to')
                ->latest('valid_from')
                ->first();

            $today = now()->toDateString();

            if ($activeHistory) {
                if ($activeHistory->valid_from->toDateString() === $today) {
                    // Same day — just update in place.
                    $activeHistory->update(['salary' => $newSalary]);
                } else {
                    // Close the current record and open a new one.
                    $activeHistory->update(['valid_to' => now()->subDay()->toDateString()]);

                    EmployeeSalaryHistory::create([
                        'employee_id' => $employee->id,
                        'salary'      => $newSalary,
                        'valid_from'  => $today,
                        'created_by'  => auth()->id(),
                    ]);
                }
            } else {
                EmployeeSalaryHistory::create([
                    'employee_id' => $employee->id,
                    'salary'      => $newSalary,
                    'valid_from'  => $today,
                    'created_by'  => auth()->id(),
                ]);
            }
        }
    }

    /**
     * Handle the Employee "deleted" (soft-delete / termination) event.
     */
    public function deleted(Employee $employee): void
    {
        $this->writeAuditLog($employee, 'employee_terminated', 'Employee record terminated and archived.', [
            'termination_reason' => $employee->termination_reason ?? null,
            'termination_type'   => $employee->termination_type ?? null,
            'final_status'       => $employee->status,
        ]);
    }

    /**
     * Handle the Employee "restored" (reinstatement) event.
     */
    public function restored(Employee $employee): void
    {
        $this->writeAuditLog($employee, 'employee_reinstated', 'Employee record reinstated.', [
            'new_status' => $employee->status,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function writeAuditLog(Employee $employee, string $action, string $message, array $context = []): void
    {
        try {
            HRAuditLog::create([
                'user_id'    => auth()->id(),
                'employee_id'=> $employee->id,
                'action'     => $action,
                'model_type' => Employee::class,
                'model_id'   => $employee->id,
                'message'    => $message,
                'context'    => $context,
                'ip_address' => Request::ip(),
            ]);
        } catch (\Exception $e) {
            // Audit failures must never break the primary operation.
            \Log::error("EmployeeObserver: failed to write audit log [{$action}] for employee {$employee->id}: " . $e->getMessage());
        }
    }
}
