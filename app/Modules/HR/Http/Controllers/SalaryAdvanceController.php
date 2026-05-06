<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalaryAdvanceController extends Controller
{
    /**
     * List all salary advance requests (for HR).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalaryAdvanceRequest::with(['employee.department']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate(15)
        ]);
    }

    /**
     * Submit a new salary advance request (for Employee).
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->employee_id) {
            return response()->json(['message' => 'No employee profile found'], 404);
        }

        $employee = Employee::find($user->employee_id);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:500',
            'target_payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Industry Standard Check: Cap at 50% of salary
        $cap = ($employee->salary ?? 0) * 0.5;
        if ($request->amount > $cap) {
            return response()->json([
                'message' => "Advance request exceeds the safety cap of 50% of your salary (Max: KES " . number_format($cap, 2) . ")"
            ], 422);
        }

        $advance = SalaryAdvanceRequest::create([
            'employee_id' => $employee->id,
            'amount' => $request->amount,
            'reason' => $request->reason,
            'target_payroll_month' => $request->target_payroll_month,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Salary advance request submitted successfully',
            'data' => $advance
        ], 201);
    }

    /**
     * Approve a salary advance request.
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $advance = SalaryAdvanceRequest::findOrFail($id);

        if ($advance->status !== 'pending') {
            return response()->json(['message' => 'Request is no longer pending'], 422);
        }

        DB::beginTransaction();
        try {
            // Create the Payroll Ledger entry
            $ledger = PayrollLedger::create([
                'employee_id' => $advance->employee_id,
                'name' => 'Salary Advance Recovery',
                'description' => "Recovery of approved advance requested on " . $advance->created_at->format('Y-m-d'),
                'type' => 'deduction',
                'amount_type' => 'fixed',
                'amount_value' => $advance->amount,
                'ledger_month' => $advance->target_payroll_month,
                'is_recurring' => false
            ]);

            $advance->update([
                'status' => 'approved',
                'hr_remarks' => $request->hr_remarks,
                'ledger_id' => $ledger->id
            ]);

            HRAuditLog::create([
                'user_id' => auth()->id(),
                'employee_id' => $advance->employee_id,
                'action' => 'salary_advance_approved',
                'model_type' => 'SalaryAdvanceRequest',
                'model_id' => $advance->id,
                'message' => "Salary advance of KES " . number_format($advance->amount, 2) . " approved for " . ($advance->employee->first_name ?? 'Employee'),
                'context' => [
                    'amount' => $advance->amount,
                    'target_month' => $advance->target_payroll_month,
                    'ledger_id' => $ledger->id,
                    'hr_remarks' => $request->hr_remarks
                ],
                'ip_address' => $request->ip()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Advance approved and ledger entry created',
                'data' => $advance
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to approve advance: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a salary advance request.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $advance = SalaryAdvanceRequest::findOrFail($id);

        if ($advance->status !== 'pending') {
            return response()->json(['message' => 'Request is no longer pending'], 422);
        }

        $request->validate(['hr_remarks' => 'required|string|max:500']);

        $advance->update([
            'status' => 'rejected',
            'hr_remarks' => $request->hr_remarks
        ]);

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'employee_id' => $advance->employee_id,
            'action' => 'salary_advance_rejected',
            'model_type' => 'SalaryAdvanceRequest',
            'model_id' => $advance->id,
            'message' => "Salary advance of KES " . number_format($advance->amount, 2) . " rejected.",
            'context' => [
                'amount' => $advance->amount,
                'target_month' => $advance->target_payroll_month,
                'hr_remarks' => $request->hr_remarks
            ],
            'ip_address' => $request->ip()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Advance request rejected',
            'data' => $advance
        ]);
    }

    /**
     * List my own requests (for Employee Self-Service).
     */
    public function myRequests(): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->employee_id) {
            return response()->json(['message' => 'No employee profile found'], 404);
        }

        $requests = SalaryAdvanceRequest::where('employee_id', $user->employee_id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }
}
