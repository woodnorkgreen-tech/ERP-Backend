<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollLedger;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use App\Modules\HR\Models\SalaryAdvanceRecovery;
use App\Constants\Permissions;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\PaymentSettlementService;
use App\Modules\Finance\Support\DocumentNumber;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Modules\HR\Exports\SalaryAdvanceRegisterExport;
use App\Modules\HR\Services\SalaryAdvanceRegister;
use App\Modules\HR\Support\Pdf\Documents\SalaryAdvanceRegisterDocument;
use Maatwebsite\Excel\Facades\Excel;

class SalaryAdvanceController extends Controller
{
    /** Nobody decides on their own advance — another HR officer must. */
    private function refuseOwnAdvance(Request $request, int $id): void
    {
        $employeeId = $request->user()?->employee_id;
        $applicant = SalaryAdvanceRequest::whereKey($id)->value('employee_id');
        abort_if($employeeId && $applicant && (int) $employeeId === (int) $applicant, 403,
            'You cannot approve or reject your own salary advance. Another HR officer must decide on it.');
    }

    /** HR decides on advances; Finance pays them. Both may see the register. */
    private function canSeeRegister(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->can(Permissions::HR_MANAGE_PAYROLL) || $user?->can(Permissions::FINANCE_SPEND_VOUCHERS_POST));
    }

    /**
     * The salary-advance register for HR (and Finance), filtered by stage,
     * person, department, payment method, recovery month and submission date.
     */
    public function index(Request $request, SalaryAdvanceRegister $register): JsonResponse
    {
        abort_unless($this->canSeeRegister($request), 403, 'Only HR or Finance can see salary advance applications.');

        $filters = $register->filters($request);
        $perPage = min(max($request->integer('per_page', 25), 5), 100);

        return response()->json([
            'success' => true,
            'data' => $register->query($filters)->paginate($perPage),
            'summary' => $register->summary($filters),
            'filters' => $filters,
        ]);
    }

    /** The filtered register as an Excel workbook for Finance. */
    public function export(Request $request, SalaryAdvanceRegister $register)
    {
        abort_unless($this->canSeeRegister($request), 403, 'Only HR or Finance can download salary advance applications.');

        $filters = $register->filters($request);
        $rows = $register->rows($filters);
        $user = $request->user();

        HRAuditLog::create([
            'user_id' => $user->id,
            'action' => 'salary_advance_register_exported',
            'model_type' => 'SalaryAdvanceRequest',
            'message' => "Salary advance register exported ({$rows->count()} applications)",
            'context' => ['filters' => $filters, 'count' => $rows->count(), 'format' => 'xlsx'],
            'ip_address' => $request->ip(),
        ]);

        $stamp = now()->format('Y-m-d_Hi');

        return Excel::download(
            new SalaryAdvanceRegisterExport($rows, $this->describeFilters($filters), $user->name ?? 'HR', now()->format('d M Y, H:i')),
            "salary-advances_{$stamp}.xlsx",
        );
    }

    /** The filtered register as a WNG-branded PDF for Finance sign-off. */
    public function exportPdf(Request $request, SalaryAdvanceRegister $register)
    {
        abort_unless($this->canSeeRegister($request), 403, 'Only HR or Finance can download salary advance applications.');

        $filters = $register->filters($request);
        $rows = $register->rows($filters);
        $user = $request->user();

        HRAuditLog::create([
            'user_id' => $user->id,
            'action' => 'salary_advance_register_exported',
            'model_type' => 'SalaryAdvanceRequest',
            'message' => "Salary advance register exported as PDF ({$rows->count()} applications)",
            'context' => ['filters' => $filters, 'count' => $rows->count(), 'format' => 'pdf'],
            'ip_address' => $request->ip(),
        ]);

        return (new SalaryAdvanceRegisterDocument($rows, $this->describeFilters($filters), $user->name ?? 'HR'))->download();
    }

    /** @return array<int, string> the filters in words, printed on the workbook */
    private function describeFilters(array $filters): array
    {
        $lines = [];
        if (! empty($filters['status'])) {
            $lines[] = 'Status: '.implode(', ', array_map(fn ($stage) => SalaryAdvanceRegister::STAGES[$stage], $filters['status']));
        }
        if (! empty($filters['department_id'])) {
            $lines[] = 'Department: '.(\App\Modules\HR\Models\Department::find($filters['department_id'])?->name ?? '#'.$filters['department_id']);
        }
        if (! empty($filters['employee_id'])) {
            $employee = Employee::find($filters['employee_id']);
            $lines[] = 'Employee: '.trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? ''));
        }
        if (! empty($filters['payment_method'])) {
            $lines[] = 'Paid via: '.str_replace('_', ' ', $filters['payment_method']);
        }
        if (! empty($filters['month_from']) || ! empty($filters['month_to'])) {
            $lines[] = 'Recover from month: '.($filters['month_from'] ?? '…').' to '.($filters['month_to'] ?? '…');
        }
        if (! empty($filters['submitted_from']) || ! empty($filters['submitted_to'])) {
            $lines[] = 'Submitted: '.($filters['submitted_from'] ?? '…').' to '.($filters['submitted_to'] ?? '…');
        }
        if (! empty($filters['search'])) {
            $lines[] = 'Search: "'.$filters['search'].'"';
        }

        return $lines;
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
        abort_unless($request->user()?->can(Permissions::HR_MANAGE_PAYROLL), 403, 'Only HR can decide on salary advances.');
        $this->refuseOwnAdvance($request, (int) $id);
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
                        'salary_advance_request_id' => $advance->id,
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
                        'salary_advance_request_id' => $advance->id,
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
                    'salary_advance_request_id' => $advance->id,
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
        abort_unless($request->user()?->can(Permissions::HR_MANAGE_PAYROLL), 403, 'Only HR can decide on salary advances.');
        $this->refuseOwnAdvance($request, (int) $id);
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
     * W3-2 (confirmed 2026-09-23): the payout of an approved advance becomes a
     * real Payment through the one settlement engine, so it is visible in
     * Payments, cash position and reconciliation.
     *
     * Deliberately NO journal. How a salary advance sits in the general ledger
     * — a Staff Advances receivable, and how payroll recovery clears it — is an
     * open Finance/accountant decision (W3-2/W3-8). Payroll accrual today
     * credits advance deductions to 2140 "Other payroll deductions payable",
     * so debiting 1300 here would leave a receivable nothing ever clears.
     * Approval ≠ Payment ≠ Recovery: this records the Payment only.
     */
    public function disburse(Request $request, int $id, PaymentSettlementService $settlements): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_POST), 403);
        $validated = $request->validate([
            'payment_source_id' => ['required', 'integer', 'exists:payment_sources,id'],
            'payment_method' => ['nullable', 'string'], 'payment_reference' => ['required', 'string', 'max:100'],
            'payment_date' => ['required', 'date'], 'transaction_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $advance = DB::transaction(function () use ($request, $id, $validated, $settlements) {
            $advance = SalaryAdvanceRequest::query()->with('employee')->lockForUpdate()->findOrFail($id);
            if ($advance->status !== 'approved' || $advance->payment_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['advance' => 'Only an approved, unpaid salary advance can be disbursed.']);
            }
            if ($request->user()->employee_id && (int) $request->user()->employee_id === (int) $advance->employee_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['advance' => 'You cannot record the payout of your own salary advance.']);
            }
            $source = PaymentSource::query()->paymentCapable()->findOrFail($validated['payment_source_id']);
            $payment = $settlements->settle([
                'payment_no' => DocumentNumber::next(DocumentNumber::PAYMENT, substr($validated['payment_date'], 0, 4)),
                'payment_type' => 'advance', 'payment_source_id' => $source->id,
                'payee_name' => trim(($advance->employee?->first_name ?? '').' '.($advance->employee?->last_name ?? '')) ?: 'Employee', 'payee_type' => 'employee', 'payee_id' => $advance->employee_id,
                'account' => 'Staff advances', 'amount' => $advance->amount,
                'description' => "Salary advance #{$advance->id}", 'date_disbursed' => $validated['payment_date'],
                'external_reference' => $validated['payment_reference'], 'payment_method' => $validated['payment_method'] ?? null,
                'transaction_cost' => $validated['transaction_cost'] ?? 0,
                // payments.classification is the client-segment enum; payroll's
                // own net-pay Payment uses 'admin' for the same reason.
                'classification' => 'admin',
                'tax' => 'no_etr', 'receipt_type' => 'none', 'created_by' => $request->user()->id,
                'source_document_type' => SalaryAdvanceRequest::class, 'source_document_id' => $advance->id,
                'idempotency_key' => 'salary-advance:'.$advance->id,
            ]);
            $advance->update(['status' => 'disbursed', 'payment_id' => $payment->id, 'paid_at' => now()]);
            return $advance->fresh(['payment', 'ledgers', 'recoveries']);
        });
        return response()->json([
            'success' => true,
            'message' => 'Salary advance payout recorded as a Payment. Its ledger treatment awaits Finance/accountant confirmation, so no journal was posted.',
            'data' => $advance,
        ]);
    }

    public function recordRecovery(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_SPEND_VOUCHERS_POST), 403);
        $validated = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'recovered_on' => ['required', 'date'],
            'payroll_run_id' => ['nullable', 'integer', 'exists:payroll_runs,id'], 'reference' => ['nullable', 'string', 'max:100']]);
        $advance = DB::transaction(function () use ($request, $id, $validated) {
            $advance = SalaryAdvanceRequest::query()->lockForUpdate()->findOrFail($id);
            $remaining = bcsub((string) $advance->amount, (string) $advance->amount_recovered, 2);
            if (! $advance->payment_id || bccomp((string) $validated['amount'], $remaining, 2) === 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Recovery requires a paid advance and cannot exceed its outstanding balance.']);
            }
            if (! empty($validated['payroll_run_id']) && $advance->recoveries()->where('payroll_run_id', $validated['payroll_run_id'])->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payroll_run_id' => 'A recovery for this payroll run is already recorded against this advance.']);
            }
            SalaryAdvanceRecovery::query()->create([...$validated, 'salary_advance_request_id' => $advance->id, 'recorded_by' => $request->user()->id]);
            $recovered = bcadd((string) $advance->amount_recovered, (string) $validated['amount'], 2);
            $advance->update(['amount_recovered' => $recovered, 'fully_recovered_at' => bccomp($recovered, (string) $advance->amount, 2) === 0 ? now() : null]);
            return $advance->fresh(['payment', 'ledgers', 'recoveries']);
        });
        return response()->json(['success' => true, 'message' => 'Recovery recorded for operational reconciliation; no recovery GL policy was assumed.', 'data' => $advance]);
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

        $requests = SalaryAdvanceRequest::with(['ledger', 'ledgers', 'payment', 'recoveries'])->where('employee_id', $user->employee_id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }
}
