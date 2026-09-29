<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Compensation;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\OvertimeService;
use App\Constants\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompensatoryLeaveController extends Controller
{
    protected $overtimeService;

    public function __construct(OvertimeService $overtimeService)
    {
        $this->overtimeService = $overtimeService;
    }

    /**
     * List compensation requests.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Compensation::with(['employee.department', 'approver:id,name']);

        // Intelligent Data Isolation: Role & Hierarchy aware
        if (!$user->hasRole(['Super Admin', 'Admin', 'HR'])) {
            $employeeId = $user->employee_id;
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $isDeptLead = $user->isDeptLead();

            if ($employeeId) {
                $query->where(function ($q) use ($employeeId, $accessibleDeptIds, $isDeptLead) {
                    // 1. Personal Requests
                    $q->where('employee_id', $employeeId);

                    // 2. Direct Subordinates
                    $q->orWhereHas('employee', function ($sub) use ($employeeId) {
                        $sub->where('manager_id', $employeeId);
                    });

                    // 3. Departmental Subordinates
                    if ($isDeptLead && !empty($accessibleDeptIds)) {
                        $q->orWhereHas('employee', function ($sub) use ($accessibleDeptIds) {
                            $sub->whereIn('department_id', $accessibleDeptIds);
                        });
                    }
                });
            } else {
                return response()->json([]);
            }
        }

        // W7-10: Technical Labour operational paths decommissioned.
        return response()->json($query->latest()->get());
    }

    /**
     * Request a compensation day off.
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'comp_date' => 'required|date',
            'type' => 'required|in:full_day,half_day',
        ]);

$targetEmployeeId = $validated['employee_id'] ?? null;

        if (!$targetEmployeeId && $user->employee_id) {
            $targetEmployeeId = $user->employee_id;
        }

        if (!$targetEmployeeId) {
            return response()->json(['message' => 'Target personnel profile not found.'], 422);
        }

        $subject = Employee::findOrFail($targetEmployeeId);

        // Security: Can I request for this person?
        $isGlobal = $user->hasRole(['Super Admin', 'HR']);
        $isOwn = $targetEmployeeId && ($targetEmployeeId == $user->employee_id);
        
        if ($targetEmployeeId) {
            $isManager = $subject->manager_id === $user->employee_id;
            $isDeptLead = $subject->department?->manager_id === $user->employee_id;
            $isProductionLead = false;
        } else {
            // Technical Labour paths are decommissioned - this path should never be reached
            $isManager = false;
            $isDeptLead = false;
            $isProductionLead = false;
        }

        if (!$isGlobal && !$isOwn && !$isManager && !$isDeptLead && !$isProductionLead) {
            abort(403, 'Unauthorized to submit a request for this personnel.');
        }

        $hoursNeeded = $validated['type'] === 'full_day' ? 8.0 : 4.0;

        if ($subject->ot_balance < $hoursNeeded) {
            return response()->json(['message' => 'Insufficient OT balance'], 422);
        }

        $comp = Compensation::create([
            'employee_id' => $targetEmployeeId,
            'comp_date' => $validated['comp_date'],
            'type' => $validated['type'],
            'hours' => $hoursNeeded,
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        return response()->json($comp->load(['employee']), 201);
    }

    /**
     * Supervisor approval for compensation request.
     */
    public function supervisorApprove(Compensation $compensation)
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR']);
        $employee = $compensation->employee;

        // Prevent Self-Approval
        if ($employee && $employee->id === $user->employee_id && !$isGlobal) {
            abort(403, 'Self-approval is restricted. Please request validation from HR or a higher authority.');
        }

        // Determine if subject is Manager/Lead
        $isSubjectManager = false;
        if ($employee) {
            $isSubjectManager = \App\Modules\HR\Models\Employee::where('manager_id', $employee->id)->exists() || 
                               \App\Modules\HR\Models\Department::where('manager_id', $employee->id)->exists();
        }

        if ($isSubjectManager) {
            if (!$isGlobal) {
                abort(403, 'Manager/Department Lead entries must be validated by HR compliance.');
            }
} else {
            $isManager = $employee && $employee->manager_id === $user->employee_id;
            $isDeptLead = $employee && $employee->department?->manager_id === $user->employee_id;
            
            // Technical Labour paths are decommissioned
            $isProductionDeptManager = false;
            
            // W7-10: Technical Labour authorization paths decommissioned
            // Historical technical_labour_id data is preserved in the database
        }

        if (!$isGlobal && !$isManager && !$isDeptLead && !$isProductionDeptManager) {
            abort(403, 'Unauthorized to approve this request.');
        }

        $this->overtimeService->supervisorApproveCompensation($compensation);
        return response()->json($compensation);
    }

    /**
     * Final HR approval (Ledger deduction).
     */
    public function hrApprove(Compensation $compensation)
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR']);
        
        // Authorization check: Only Global Admins can finalize deductions
        if (!$isGlobal) {
            abort(403, 'Only HR or System Administrators can authorize ledger deductions.');
        }

        try {
            $this->overtimeService->hrApproveCompensation($compensation);
            return response()->json($compensation);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reject compensation request.
     */
    public function reject(\Illuminate\Http\Request $request, Compensation $compensation)
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR']);
        $employee = $compensation->employee;

        $isManager = $employee && $employee->manager_id === $user->employee_id;
        $isDeptLead = $employee && $employee->department?->manager_id === $user->employee_id;
        
        // W7-10: Technical Labour paths are decommissioned
        $isProductionDeptManager = false;

        if (!$isGlobal && !$isManager && !$isDeptLead && !$isProductionDeptManager) {
            abort(403, 'Unauthorized to reject this request.');
        }

        $reason = $request->input('reason');
        $updateData = ['status' => 'rejected'];
        if ($reason) {
            $updateData['notes'] = $reason;
        }

        $compensation->update($updateData);
        
        $subjectName = $employee ? $employee->name : 'Unknown';

        \App\Modules\HR\Models\SystemEvent::log('rejected', 'compensation', $compensation->id, [
            'actor' => $user->name,
            'employee' => $subjectName,
            'reason' => $reason
        ]);

        return response()->json($compensation);
    }

    public function destroy($id)
    {
        $user = auth()->user();
        if (!$user->hasRole(['Super Admin'])) {
            abort(403, 'Unauthorized. Only Super Admin can delete transactions.');
        }

        $compensation = Compensation::findOrFail($id);
        $compensation->delete(); // Soft delete

        return response()->json(['message' => 'Transaction deleted successfully.']);
    }
}
