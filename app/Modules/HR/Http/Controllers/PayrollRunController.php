<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Services\Payroll\PayrollService;
use App\Modules\HR\Services\Payroll\PayrollFinancePostingService;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Notifications\Services\NotificationService;
use App\Constants\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollRunController extends Controller
{
    public function __construct(
        protected PayrollService $payrollService,
        protected PayrollFinancePostingService $financePosting,
    ) {}

    /**
     * List all payroll runs.
     */
    public function index(Request $request): JsonResponse
    {
        $runs = PayrollRun::with('creator')
            ->orderBy('payroll_month', 'desc')
            ->paginate($request->integer('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => $runs->items(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ]
        ]);
    }

    /**
     * Create/initialize a new payroll run for a month.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_month' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $existing = PayrollRun::where('payroll_month', $validated['payroll_month'])->first();

        if ($existing) {
            if ($existing->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => "A payroll run for {$validated['payroll_month']} already exists with status: {$existing->status}."
                ], 422);
            }
            // Return existing draft idempotently
            return response()->json(['success' => true, 'data' => $existing], 200);
        }

        $run = $this->payrollService->initializeRun($validated['payroll_month']);

        return response()->json(['success' => true, 'data' => $run], 201);
    }

    /**
     * Show a single payroll run with its payslip stats.
     */
    public function show(PayrollRun $payrollRun): JsonResponse
    {
        $payrollRun->load(['creator', 'accrualJournal', 'paymentJournal', 'paymentSource']);

        $stats = DB::table('payslips')
            ->where('payroll_run_id', $payrollRun->id)
            ->selectRaw('COUNT(*) as total, SUM(gross_pay) as total_gross, SUM(net_pay) as total_net')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $payrollRun,
            'stats' => $stats,
        ]);
    }

    /**
     * Process all active employees for this run synchronously.
     */
    public function process(Request $request, PayrollRun $payrollRun, PayrollService $service): JsonResponse
    {
        if (!in_array($payrollRun->status, ['draft', 'processing'], true)) {
            return response()->json(['success' => false, 'message' => "This payroll run is {$payrollRun->status} and cannot be reprocessed."], 422);
        }

        $query = Employee::where('status', 'active');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('employee_ids')) {
            $query->whereIn('id', $request->input('employee_ids'));
        }

        $employees = $query->get();

        // Increase time limit for synchronous processing of the batch
        set_time_limit(300);

        DB::transaction(function () use ($employees, $payrollRun, $service) {
            $payrollRun->update(['status' => 'processing']);

            foreach ($employees as $employee) {
                $service->processEmployee($employee, $payrollRun);
            }

            // A filtered batch is only one slice of the run. Reconcile summaries
            // from every attached payslip so prior batches never disappear from
            // the figures shown for review.
            $allRunPayslips = $payrollRun->payslips()->get();
            $totalStatutory = $allRunPayslips->sum(function ($p) {
                $b = $p->tax_breakdown ?? [];
                return ($b['paye'] ?? 0) + ($b['nssf'] ?? 0) + ($b['shif'] ?? 0) + ($b['housing_levy'] ?? 0);
            });

            $payrollRun->update([
                'total_gross'     => $allRunPayslips->sum('gross_pay'),
                'total_net'       => $allRunPayslips->sum('net_pay'),
                'total_statutory' => $totalStatutory,
                'status'          => 'processing',
            ]);
        });

        NotificationService::send(
            type: 'payroll_run_processed',
            title: 'Payroll Run Processed',
            message: "Payroll for {$payrollRun->payroll_month} has been processed for {$employees->count()} employees and is ready for review.",
            module: 'hr',
            data: ['payroll_run_id' => $payrollRun->id, 'url' => "/hr/payroll/runs/{$payrollRun->id}"],
            permission: Permissions::HR_MANAGE_PAYROLL,
        );

        return response()->json([
            'success' => true,
            'message' => "Successfully processed payroll for {$employees->count()} employees.",
            'run_id' => $payrollRun->id,
        ]);
    }

    /**
     * Lock the payroll run, compute totals, prevent further changes.
     */
    public function lock(PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'processing') {
            return response()->json(['success' => false, 'message' => 'Only a processed payroll run can be locked.'], 422);
        }

        $payslips = $payrollRun->payslips()->get();
        if ($payslips->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'A payroll run with no payslips cannot be locked.'], 422);
        }

        $uncovered = $payslips->sum(fn (Payslip $payslip) => (float) data_get($payslip->tax_breakdown, 'uncovered_deductions', 0));
        if ($uncovered > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Resolve uncovered employee deductions before locking this payroll run.',
                'uncovered_deductions' => round($uncovered, 2),
            ], 422);
        }

        DB::transaction(function () use ($payrollRun) {
            $this->payrollService->finalizeRun($payrollRun);
            $this->financePosting->postAccrual($payrollRun->fresh());
        });
        $payrollRun->refresh();

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'payroll_run_locked',
            'model_type' => 'PayrollRun',
            'model_id' => $payrollRun->id,
            'message' => "Payroll run for {$payrollRun->payroll_month} locked.",
            'context' => [
                'payroll_month' => $payrollRun->payroll_month,
                'total_gross' => $payrollRun->total_gross,
                'total_net' => $payrollRun->total_net
            ],
            'ip_address' => request()->ip()
        ]);

        NotificationService::send(
            type: 'payroll_run_finalized',
            title: 'Payroll Run Finalized',
            message: "Payroll run for {$payrollRun->payroll_month} has been locked. Total net: KES " . number_format($payrollRun->total_net, 2),
            module: 'hr',
            data: ['payroll_run_id' => $payrollRun->id, 'url' => "/hr/payroll/runs/{$payrollRun->id}"],
            permission: Permissions::HR_MANAGE_PAYROLL,
        );

        return response()->json(['success' => true, 'data' => $payrollRun->fresh()]);
    }

    /**
     * Mark a locked run as paid.
     */
    public function markPaid(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'locked') {
            return response()->json(['success' => false, 'message' => 'Only locked runs can be marked as paid.'], 422);
        }

        $validated = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['required', 'string', 'max:100'],
        ]);
        $paymentSource = PaymentSource::findOrFail($validated['payment_source_id']);

        DB::transaction(function () use ($payrollRun, $paymentSource, $validated) {
            $lockedRun = PayrollRun::whereKey($payrollRun->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== 'locked') {
                throw new \DomainException('Only locked runs can be marked as paid.');
            }

            $this->financePosting->postPayment(
                $lockedRun,
                $paymentSource,
                $validated['payment_date'],
                $validated['payment_reference'],
            );
            $lockedRun->update(['status' => 'paid']);
            $lockedRun->payslips()->update(['status' => 'paid', 'payment_date' => $validated['payment_date']]);
        });
        $payrollRun->refresh();

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'payroll_run_paid',
            'model_type' => 'PayrollRun',
            'model_id' => $payrollRun->id,
            'message' => "Payroll run for {$payrollRun->payroll_month} marked as PAID.",
            'context' => [
                'payroll_month' => $payrollRun->payroll_month,
                'payment_date' => $payrollRun->payment_date?->toDateString(),
                'payment_reference' => $payrollRun->payment_reference,
                'payment_journal_entry_id' => $payrollRun->payment_journal_entry_id,
            ],
            'ip_address' => request()->ip()
        ]);

        // Notify each paid employee individually that their payslip is ready.
        $payslips = $payrollRun->payslips()->with('employee.user')->get();
        foreach ($payslips as $payslip) {
            $recipientUser = $payslip->employee?->user;
            if (!$recipientUser) {
                continue;
            }

            NotificationService::send(
                type: 'payroll_payslip_ready',
                title: 'Payslip Ready',
                message: "Your payslip for {$payrollRun->payroll_month} is ready. Net pay: KES " . number_format($payslip->net_pay, 2),
                module: 'hr',
                data: ['payroll_run_id' => $payrollRun->id, 'payslip_id' => $payslip->id, 'url' => "/self-service/payslips/{$payslip->id}"],
                users: [$recipientUser],
            );
        }

        return response()->json(['success' => true, 'data' => $payrollRun->fresh()]);
    }

    /**
     * Rollback a run from Locked/Paid back to Draft (Admin only).
     */
    public function rollback(PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Paid payroll cannot be rolled back. Record a controlled adjustment or financial reversal instead.',
            ], 422);
        }

        if ($payrollRun->status !== 'locked') {
            return response()->json(['success' => false, 'message' => 'Only a locked unpaid run can be returned to draft.'], 422);
        }

        if ($payrollRun->accrual_journal_entry_id) {
            return response()->json([
                'success' => false,
                'message' => 'This payroll is posted to finance and cannot return to draft. Post a controlled reversal instead.',
            ], 422);
        }

        $payrollRun->update(['status' => 'draft']);
        
        // Also reset payslip statuses
        $payrollRun->payslips()->update(['status' => 'draft', 'payment_date' => null]);

        HRAuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'payroll_run_rollback',
            'model_type' => 'PayrollRun',
            'model_id' => $payrollRun->id,
            'message' => "Payroll run for {$payrollRun->payroll_month} rolled back to DRAFT.",
            'context' => [
                'payroll_month' => $payrollRun->payroll_month,
                'previous_status' => 'locked' // Or however we track it
            ],
            'ip_address' => request()->ip()
        ]);

        return response()->json(['success' => true, 'message' => 'Run rolled back to Draft state.', 'data' => $payrollRun->fresh()]);
    }

    /**
     * Delete a payroll run.
     */
    public function destroy(PayrollRun $payrollRun): JsonResponse
    {
        if (in_array($payrollRun->status, ['locked', 'paid'])) {
            return response()->json(['success' => false, 'message' => 'Cannot delete a locked or paid payroll run. Rollback to Draft first.'], 422);
        }

        DB::transaction(function () use ($payrollRun) {
            $payrollRun->payslips()->delete();
            $payrollRun->delete();
        });

        return response()->json(['success' => true, 'message' => 'Payroll run deleted.']);
    }

    // ── SALARY HISTORY ────────────────────────────────────────────────────────

    /**
     * Get salary history for an employee.
     */
    public function salaryHistory(Employee $employee): JsonResponse
    {
        $history = $employee->salaryHistory()->with('creator')->get();

        return response()->json(['success' => true, 'data' => $history]);
    }

    /**
     * Manually add a salary history entry (for backdating / corrections).
     */
    public function storeSalaryHistory(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'salary' => 'required|numeric|min:0',
            'valid_from' => 'required|date',
        ]);

        DB::transaction(function () use ($validated, $employee) {
            // Close the current open record (if any)
            $active = EmployeeSalaryHistory::where('employee_id', $employee->id)
                ->whereNull('valid_to')
                ->latest('valid_from')
                ->first();

            if ($active) {
                $active->update(['valid_to' => now()->subDay()->toDateString()]);
            }

            EmployeeSalaryHistory::create([
                'employee_id' => $employee->id,
                'salary' => $validated['salary'],
                'valid_from' => $validated['valid_from'],
                'created_by' => auth()->id(),
            ]);

            // Also update the live employee record
            $employee->update(['salary' => $validated['salary']]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Salary history updated.',
            'data' => $employee->salaryHistory()->latest()->first()
        ]);
    }

    /**
     * Get compliance summary (tax totals) for a specific run.
     */
    public function complianceSummary(PayrollRun $payrollRun): JsonResponse
    {
        $breakdown = DB::table('payslips')
            ->where('payroll_run_id', $payrollRun->id)
            ->selectRaw('
                COUNT(*) as employee_count,
                COUNT(CASE WHEN status = "paid" THEN 1 END) as paid_count,
                SUM(gross_pay) as total_gross,
                SUM(net_pay) as total_net,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.paye")) AS DECIMAL(10,2))) as total_paye,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.nssf")) AS DECIMAL(10,2))) as total_nssf,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.shif")) AS DECIMAL(10,2))) as total_shif,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(tax_breakdown, "$.housing_levy")) AS DECIMAL(10,2))) as total_housing_levy
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $breakdown
        ]);
    }
}
