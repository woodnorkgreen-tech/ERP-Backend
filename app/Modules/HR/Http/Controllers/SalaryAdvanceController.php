<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use App\Modules\Notifications\Services\NotificationService;
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
        $query = SalaryAdvanceRequest::with(['employee.department', 'ledger']);

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

        NotificationService::send(
            type: 'salary_advance_requested',
            title: 'Salary Advance Requested',
            message: "{$employee->name} requested a salary advance of KES " . number_format($advance->amount, 2),
            module: 'hr',
            data: ['advance_id' => $advance->id, 'url' => "/hr/advances/{$advance->id}"],
            role: ['Super Admin', 'HR'],
        );

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

        $validator = Validator::make($request->all(), [
            'split_installments' => 'nullable|boolean',
            'monthly_installment' => 'nullable|numeric|min:1',
            'hr_remarks' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $splitInstallments = $request->boolean('split_installments', false);
            $monthlyInstallment = floatval($request->input('monthly_installment', 0));

            $createdLedgers = [];
            $primaryLedgerId = null;

            if ($splitInstallments && $monthlyInstallment > 0) {
                $principal = floatval($advance->amount);
                $times = intval(floor($principal / $monthlyInstallment));
                $remainder = fmod($principal, $monthlyInstallment);

                if ($times > 0) {
                    $isEntryRecurring = !($times === 1 && $remainder == 0);
                    $endMonth = null;
                    if ($isEntryRecurring) {
                        $start = \Carbon\Carbon::createFromFormat('Y-m', $advance->target_payroll_month);
                        $endMonth = $start->copy()->addMonths($times - 1)->format('Y-m');
                    }

                    $ledger = PayrollLedger::create([
                        'employee_id' => $advance->employee_id,
                        'name' => 'Salary Advance Recovery (Split KES ' . number_format($monthlyInstallment) . '/mo)',
                        'description' => "Recovery of approved advance requested on " . $advance->created_at->format('Y-m-d') . " (Split) | Total Principal: KES " . number_format($principal, 2),
                        'type' => 'deduction',
                        'amount_type' => 'fixed',
                        'amount_value' => $monthlyInstallment,
                        'ledger_month' => $advance->target_payroll_month,
                        'is_recurring' => $isEntryRecurring,
                        'recurring_end_month' => $endMonth
                    ]);
                    $createdLedgers[] = $ledger->id;
                }

                if ($remainder > 0) {
                    $start = \Carbon\Carbon::createFromFormat('Y-m', $advance->target_payroll_month);
                    $remainderMonth = $start->copy()->addMonths($times)->format('Y-m');

                    $ledger = PayrollLedger::create([
                        'employee_id' => $advance->employee_id,
                        'name' => 'Salary Advance Recovery (Remainder)',
                        'description' => "Remainder recovery of approved advance requested on " . $advance->created_at->format('Y-m-d') . " | Total Principal: KES " . number_format($principal, 2),
                        'type' => 'deduction',
                        'amount_type' => 'fixed',
                        'amount_value' => $remainder,
                        'ledger_month' => $remainderMonth,
                        'is_recurring' => false,
                        'recurring_end_month' => null
                    ]);
                    $createdLedgers[] = $ledger->id;
                }

                $primaryLedgerId = !empty($createdLedgers) ? $createdLedgers[0] : null;
            } else {
                $ledger = PayrollLedger::create([
                    'employee_id' => $advance->employee_id,
                    'name' => 'Salary Advance Recovery',
                    'description' => "Recovery of approved advance requested on " . $advance->created_at->format('Y-m-d') . " | Total Principal: KES " . number_format($advance->amount, 2),
                    'type' => 'deduction',
                    'amount_type' => 'fixed',
                    'amount_value' => $advance->amount,
                    'ledger_month' => $advance->target_payroll_month,
                    'is_recurring' => false
                ]);
                $primaryLedgerId = $ledger->id;
                $createdLedgers[] = $ledger->id;
            }

            $advance->update([
                'status' => 'approved',
                'hr_remarks' => $request->hr_remarks,
                'ledger_id' => $primaryLedgerId
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
                    'ledger_id' => $primaryLedgerId,
                    'created_ledger_ids' => $createdLedgers,
                    'hr_remarks' => $request->hr_remarks,
                    'split_installments' => $splitInstallments,
                    'monthly_installment' => $monthlyInstallment
                ],
                'ip_address' => $request->ip()
            ]);

            DB::commit();

            $recipientUser = $advance->employee?->user;
            if ($recipientUser) {
                NotificationService::send(
                    type: 'salary_advance_approved',
                    title: 'Salary Advance Approved',
                    message: "Your salary advance of KES " . number_format($advance->amount, 2) . " has been approved.",
                    module: 'hr',
                    data: ['advance_id' => $advance->id, 'url' => "/hr/advances/{$advance->id}"],
                    users: [$recipientUser],
                );
            }

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

        $recipientUser = $advance->employee?->user;
        if ($recipientUser) {
            NotificationService::send(
                type: 'salary_advance_rejected',
                title: 'Salary Advance Rejected',
                message: "Your salary advance request was rejected: {$request->hr_remarks}",
                module: 'hr',
                data: ['advance_id' => $advance->id, 'reason' => $request->hr_remarks, 'url' => "/hr/advances/{$advance->id}"],
                users: [$recipientUser],
            );
        }

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

        $requests = SalaryAdvanceRequest::with('ledger')->where('employee_id', $user->employee_id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }
}
