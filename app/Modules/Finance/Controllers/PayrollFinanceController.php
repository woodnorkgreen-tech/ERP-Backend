<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Payroll\LabourClassificationService;
use App\Modules\Finance\Payroll\PayrollPaymentService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Aggregate Finance payroll controls. Employee salary and bank detail stay in HR. */
class PayrollFinanceController extends Controller
{
    public function __construct(private PayrollPaymentService $payments, private LabourClassificationService $classifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate(['state' => ['nullable', Rule::in(['preparation', 'awaiting_posting', 'outstanding', 'settled'])], 'search' => ['nullable', 'string', 'max:60']]);
        $runs = PayrollRun::query()->withCount('payslips')->orderByDesc('payroll_month')->orderByDesc('id')->get();
        if ($search = $filters['search'] ?? null) $runs = $runs->filter(fn (PayrollRun $run) => str_contains(strtolower($run->payroll_month), strtolower($search)));
        $rows = $runs->map(fn (PayrollRun $run) => $this->runRow($request, $run));
        if ($state = $filters['state'] ?? null) $rows = $rows->where('group', $state);
        $readiness = $this->readinessData();
        return response()->json(['data' => [
            'summary' => ['current_payroll' => $rows->first(), 'net_payroll' => $this->netPosition($runs), 'statutory_liabilities' => $this->statutoryPosition($runs), 'readiness' => $readiness['summary']],
            'counts' => ['all' => $rows->count(), 'awaiting_posting' => $rows->where('group', 'awaiting_posting')->count(), 'outstanding' => $rows->where('group', 'outstanding')->count(), 'settled' => $rows->where('group', 'settled')->count(), 'exceptions' => $readiness['summary']['exceptions']],
            'runs' => $rows->values(), 'historical_note' => 'Historical payroll Payment records and opening balances are not backfilled.',
        ]]);
    }

    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authoriseRead($request);
        $run->loadCount('payslips')->load(['accrualJournal.lines.account:id,code,name', 'paymentJournal.lines.account:id,code,name', 'paymentSource:id,code,name,type', 'payment']);
        $ids = collect([$run->created_by, $run->locked_by, $run->paid_by, $run->payment?->created_by, $run->payment?->voided_by])->filter();
        $people = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $audit = HRAuditLog::query()->where('model_type', 'PayrollRun')->where('model_id', $run->id)->orderBy('created_at')->get(['id', 'user_id', 'action', 'message', 'created_at']);
        $auditPeople = User::query()->whereIn('id', $audit->pluck('user_id')->filter())->pluck('name', 'id');
        return response()->json(['data' => array_merge($this->runRow($request, $run), [
            'control_chain' => $this->controlChain($run),
            'accounting' => ['accrual' => $this->entry($run->accrualJournal), 'payment' => $this->entry($run->paymentJournal)],
            'liabilities' => $this->liabilitiesFor($run),
            'payment' => $run->payment ? ['id' => $run->payment->id, 'number' => $run->payment->payment_no, 'status' => $run->payment->status, 'amount' => $this->money($run->payment->amount), 'date' => $run->payment->date_disbursed, 'reference' => $run->payment->external_reference, 'source' => $run->paymentSource?->only(['id', 'code', 'name', 'type']), 'recorded_by' => $this->person($people, $run->payment->created_by), 'voided_by' => $this->person($people, $run->payment->voided_by), 'voided_at' => $run->payment->voided_at?->toIso8601String(), 'void_reason' => $run->payment->void_reason, 'reversal_supported' => false, 'reversal_note' => 'Payroll payment reversal is blocked until liability restoration policy is approved.'] : null,
            'controls' => ['prepared_by' => $this->person($people, $run->created_by), 'prepared_at' => $run->created_at?->toIso8601String(), 'approved_by' => $this->person($people, $run->locked_by), 'approved_at' => $run->accrualJournal?->posted_at?->toIso8601String(), 'posted_by' => $run->accrualJournal ? $this->person($people, $run->accrualJournal->created_by) : null, 'posted_at' => $run->accrualJournal?->posted_at?->toIso8601String(), 'paid_by' => $this->person($people, $run->paid_by), 'paid_at' => $run->paymentJournal?->posted_at?->toIso8601String()],
            'history' => $audit->map(fn (HRAuditLog $log) => ['id' => $log->id, 'event' => $log->action, 'message' => $log->message, 'by' => $this->person($auditPeople, $log->user_id), 'at' => $log->created_at?->toIso8601String()])->values(),
            'privacy' => 'Aggregate Finance view. Employee payslips, salaries, deductions, contacts and bank details are not exposed.',
        ])]);
    }

    public function liabilities(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $runs = PayrollRun::query()->whereNotNull('accrual_journal_entry_id')->with('accrualJournal.lines.account:id,code,name')->orderByDesc('payroll_month')->get();
        return response()->json(['data' => $runs->flatMap(fn (PayrollRun $run) => $this->liabilitiesFor($run))->values(), 'meta' => ['partial_settlement_supported' => false, 'historical_opening_position' => 'not_migrated']]);
    }

    public function paymentRegister(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $runs = PayrollRun::query()->whereNotNull('payment_id')->with(['paymentSource:id,code,name,type', 'payment'])->orderByDesc('payment_date')->get();
        return response()->json(['data' => $runs->map(fn (PayrollRun $run) => ['id' => $run->payment?->id, 'payment_no' => $run->payment?->payment_no, 'period' => $run->payroll_month, 'liability' => 'Net Payroll Payable', 'amount' => $this->money($run->payment?->amount), 'source' => $run->paymentSource?->only(['id', 'code', 'name', 'type']), 'reference' => $run->payment?->external_reference, 'date' => $run->payment_date?->toDateString(), 'status' => $run->payment?->status, 'run_id' => $run->id])->values()]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        return response()->json(['data' => $this->readinessData()]);
    }

    public function labourClassification(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $inForce = $this->classifications->mapOn(now());
        $departments = Department::query()->withCount(['employees as active_employees_count' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get();
        $history = DB::table('department_labour_classifications as c')->leftJoin('users as u', 'u.id', '=', 'c.set_by')->orderByDesc('c.effective_from')->orderByDesc('c.id')->get(['c.id', 'c.department_id', 'c.classification', 'c.effective_from', 'c.reason', 'c.set_by', 'c.created_at', 'u.name as set_by_name'])->groupBy('department_id');
        $rows = $departments->map(function (Department $department) use ($inForce, $history) {
            $events = $history->get($department->id, collect());
            $current = $events->first(fn ($row) => $row->effective_from <= now()->toDateString());
            return ['id' => $department->id, 'name' => $department->name, 'classification' => $inForce[$department->id] ?? LabourClassificationService::UNCLASSIFIED, 'posts_as' => $inForce[$department->id] ?? LabourClassificationService::INDIRECT, 'effective_from' => $current?->effective_from, 'active_employees' => (int) $department->active_employees_count, 'changed_by' => $current?->set_by ? ['id' => (int) $current->set_by, 'name' => $current->set_by_name ?: 'Former user'] : null, 'history' => $events->map(fn ($event) => ['id' => $event->id, 'classification' => $event->classification, 'effective_from' => $event->effective_from, 'reason' => $event->reason, 'changed_by' => $event->set_by ? ['id' => (int) $event->set_by, 'name' => $event->set_by_name ?: 'Former user'] : null, 'recorded_at' => \Illuminate\Support\Carbon::parse($event->created_at)->toIso8601String()])->values()];
        });
        return response()->json(['data' => $rows, 'summary' => ['departments' => $rows->count(), 'direct' => $rows->where('classification', 'direct')->count(), 'indirect' => $rows->where('classification', 'indirect')->count(), 'unclassified' => $rows->where('classification', 'unclassified')->count()], 'meta' => ['can_manage' => $request->user()->can(Permissions::FINANCE_PAYROLL_LABOUR_CLASSIFICATION_MANAGE)]]);
    }

    public function classify(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYROLL_LABOUR_CLASSIFICATION_MANAGE), 403);
        $data = $request->validate(['classification' => ['required', Rule::in(['direct', 'indirect'])], 'effective_from' => ['required', 'date'], 'reason' => ['required', 'string', 'max:500']]);
        $this->classifications->classify($department, $data['classification'], $data['effective_from'], $request->user()->id, $data['reason']);
        return response()->json(['message' => 'Labour classification recorded. Posted payroll runs are unchanged.']);
    }

    public function pay(Request $request, PayrollRun $run): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYROLL_PAY), 403);
        $data = $request->validate(['payment_source_id' => ['required', 'integer', Rule::exists('payment_sources', 'id')], 'payment_date' => ['required', 'date'], 'payment_reference' => ['required', 'string', 'max:120']]);
        $source = PaymentSource::query()->paymentCapable()->findOrFail($data['payment_source_id']);
        try {
            $run = $this->payments->pay($run, $source, $data['payment_date'], $data['payment_reference'], $request->user()->id);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['message' => 'Payroll payment recorded and Net Payroll Payable settled.', 'data' => ['id' => $run->id, 'status' => $run->status]]);
    }

    private function runRow(Request $request, PayrollRun $run): array
    {
        $settled = $run->payment_journal_entry_id !== null && $run->status === 'paid';
        $posted = $run->accrual_journal_entry_id !== null;
        $group = $settled ? 'settled' : ($posted ? 'outstanding' : ($run->status === 'locked' ? 'awaiting_posting' : 'preparation'));
        $refusal = $request->user()?->can(Permissions::FINANCE_PAYROLL_PAY) ? $this->payments->refusal($run, $request->user()->id) : 'You do not have payroll payment permission.';
        return ['id' => $run->id, 'reference' => 'PAYROLL-'.$run->payroll_month, 'period' => $run->payroll_month, 'employees' => (int) ($run->payslips_count ?? $run->payslips()->count()), 'gross' => $this->money($run->total_gross), 'net' => $this->money($run->total_net), 'statutory' => $this->money($run->total_statutory), 'status' => $run->status, 'preparation_state' => in_array($run->status, ['draft', 'processing'], true) ? $run->status : 'prepared', 'approval_state' => in_array($run->status, ['locked', 'paid'], true) ? 'approved' : 'pending', 'posting_state' => $posted ? 'posted' : 'not_posted', 'settlement_state' => $settled ? 'settled' : ($posted ? 'outstanding' : 'not_due'), 'outstanding' => $this->money($posted && ! $settled ? $run->total_net : 0), 'group' => $group, 'actions' => ['pay' => ['allowed' => $refusal === null, 'reason' => $refusal]]];
    }

    private function controlChain(PayrollRun $run): array
    {
        return [
            ['key' => 'prepared', 'label' => 'Payroll prepared', 'state' => in_array($run->status, ['draft', 'processing']) ? 'current' : 'complete'],
            ['key' => 'approved', 'label' => 'Payroll approved', 'state' => in_array($run->status, ['locked', 'paid']) ? 'complete' : 'pending'],
            ['key' => 'posted', 'label' => 'Finance posting', 'state' => $run->accrual_journal_entry_id ? 'complete' : 'pending'],
            ['key' => 'liability', 'label' => 'Net payroll liability', 'state' => $run->accrual_journal_entry_id ? 'complete' : 'pending'],
            ['key' => 'payment', 'label' => 'Payment', 'state' => $run->payment_journal_entry_id ? 'complete' : 'pending'],
            ['key' => 'settlement', 'label' => 'Settlement', 'state' => $run->status === 'paid' ? 'complete' : 'pending'],
        ];
    }

    private function liabilitiesFor(PayrollRun $run): array
    {
        $entry = $run->relationLoaded('accrualJournal') ? $run->accrualJournal : $run->accrualJournal()->with('lines.account:id,code,name')->first();
        if (! $entry) return [];
        $codes = [ChartAccountMap::local(FinanceAccountFunctions::NET_PAYROLL_PAYABLE) => ['net_payroll', 'Net Payroll Payable'], ChartAccountMap::local(FinanceAccountFunctions::PAYE_PAYABLE) => ['paye', 'PAYE Payable'], ChartAccountMap::local(FinanceAccountFunctions::STATUTORY_PAYABLE) => ['statutory', 'Statutory & Other Deductions']];
        $rows = [];
        foreach ($entry->lines->where('entry_type', 'credit') as $line) {
            $definition = $codes[$line->account?->code] ?? null;
            if (! $definition) continue;
            [$type, $label] = $definition;
            $settled = $type === 'net_payroll' && $run->payment_journal_entry_id && $run->status === 'paid' ? (string) $line->amount : '0.00';
            $outstanding = bcsub((string) $line->amount, $settled, 2);
            $rows[] = ['id' => $run->id.'-'.$type, 'run_id' => $run->id, 'period' => $run->payroll_month, 'type' => $type, 'label' => $label, 'account' => $line->account?->only(['id', 'code', 'name']), 'recognised' => $this->money($line->amount), 'settled' => $this->money($settled), 'outstanding' => $this->money($outstanding), 'status' => bccomp($outstanding, '0.00', 2) === 0 ? 'settled' : 'outstanding', 'settlement_supported' => $type === 'net_payroll', 'note' => $type === 'net_payroll' ? null : 'No statutory remittance settlement rail exists yet; liability remains outstanding.', 'journal_entry_id' => $entry->id];
        }
        return $rows;
    }

    private function entry(?JournalEntry $entry): ?array
    {
        if (! $entry) return null;
        if (! $entry->relationLoaded('lines')) $entry->load('lines.account:id,code,name');
        return ['id' => $entry->id, 'number' => $entry->entry_no, 'date' => $entry->posting_date?->toDateString(), 'status' => $entry->status, 'description' => $entry->description, 'total_debits' => $this->money($entry->total_debit), 'total_credits' => $this->money($entry->total_credit), 'delta' => $this->money(bcsub((string) $entry->total_debit, (string) $entry->total_credit, 2)), 'lines' => $entry->lines->map(fn ($line) => ['account' => $line->account?->only(['id', 'code', 'name']), 'side' => $line->entry_type, 'amount' => $this->money($line->amount), 'description' => $line->description])->values()];
    }

    private function readinessData(): array
    {
        $active = Employee::query()->where('status', 'active');
        $activeCount = (clone $active)->count();
        $configured = (clone $active)->whereNotNull('salary')->where('salary', '>', 0)->count();
        $missing = (clone $active)->whereNull('salary')->count();
        $zero = (clone $active)->whereNotNull('salary')->where('salary', '<=', 0)->count();
        $historyIds = DB::table('employee_salary_histories')->whereNull('valid_to')->whereIn('employee_id', (clone $active)->select('id'))->pluck('employee_id');
        $stale = (clone $active)->where('salary', '>', 0)->whereNotIn('id', $historyIds)->count();
        $classes = $this->classifications->mapOn(now());
        $departments = Department::query()->count();
        $unclassified = max(0, $departments - count($classes));
        $mapping = collect([FinanceAccountFunctions::SALARIES_EXPENSE, FinanceAccountFunctions::COS_DIRECT_LABOUR, FinanceAccountFunctions::NET_PAYROLL_PAYABLE, FinanceAccountFunctions::PAYE_PAYABLE, FinanceAccountFunctions::STATUTORY_PAYABLE])->map(function (string $function) { $code = ChartAccountMap::local($function); return ['function' => $function, 'code' => $code, 'ready' => ChartOfAccount::postable()->where('code', $code)->exists()]; });
        $period = AccountingPeriod::forDate(now()->endOfMonth());
        $exceptions = $missing + $zero + $stale + $unclassified + $mapping->where('ready', false)->count() + ($period?->isOpen() ? 0 : 1);
        return ['summary' => ['active_employees' => $activeCount, 'salary_configured' => $configured, 'salary_missing' => $missing, 'zero_salary_review' => $zero, 'stale_configuration' => $stale, 'departments' => $departments, 'unclassified_departments' => $unclassified, 'mapping_exceptions' => $mapping->where('ready', false)->count(), 'exceptions' => $exceptions, 'state' => $exceptions === 0 ? 'ready' : 'not_ready'], 'salary' => ['active' => $activeCount, 'configured' => $configured, 'missing' => $missing, 'zero_review' => $zero, 'stale' => $stale], 'finance_mapping' => $mapping->values(), 'labour_classification' => ['classified' => count($classes), 'unclassified' => $unclassified], 'accounting_period' => $period ? ['id' => $period->id, 'label' => $period->starts_on?->format('F Y'), 'status' => $period->status, 'open' => $period->isOpen()] : ['id' => null, 'label' => now()->format('F Y'), 'status' => 'missing', 'open' => false], 'privacy' => 'Counts only. No employee salary amount or personal detail is returned.'];
    }

    private function netPosition(Collection $runs): array
    {
        $posted = $runs->whereNotNull('accrual_journal_entry_id')->sum(fn ($run) => (float) $run->total_net);
        $settled = $runs->whereNotNull('payment_journal_entry_id')->where('status', 'paid')->sum(fn ($run) => (float) $run->total_net);
        return ['recognised' => $this->money($posted), 'settled' => $this->money($settled), 'outstanding' => $this->money($posted - $settled)];
    }

    private function statutoryPosition(Collection $runs): array
    {
        $amount = $runs->whereNotNull('accrual_journal_entry_id')->sum(fn ($run) => (float) $run->total_statutory);
        return ['recognised' => $this->money($amount), 'settled' => '0.00', 'outstanding' => $this->money($amount), 'settlement_supported' => false];
    }

    private function person(Collection $people, mixed $id): ?array { return $id ? ['id' => (int) $id, 'name' => $people[(int) $id] ?? 'Former user'] : null; }
    private function money(mixed $amount): string { return number_format((float) ($amount ?? 0), 2, '.', ''); }
    private function authoriseRead(Request $request): void { abort_unless($request->user()?->can(Permissions::FINANCE_PAYROLL_READ), 403); }
}
