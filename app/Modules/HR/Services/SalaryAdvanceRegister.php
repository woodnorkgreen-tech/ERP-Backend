<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The salary-advance register: one filter and one row shape shared by the HR
 * list and the Excel handed to Finance, so the download always matches what
 * HR was looking at.
 */
class SalaryAdvanceRegister
{
    /** Stages HR filters by. Stored status alone cannot say "paid but not yet recovered". */
    public const STAGES = [
        'pending' => 'Pending approval',
        'approved' => 'Approved — awaiting payout',
        'paid' => 'Paid',
        'outstanding' => 'Paid — recovery outstanding',
        'recovered' => 'Fully recovered',
        'rejected' => 'Rejected',
    ];

    /** @return array<string, mixed> the filters actually applied, normalised */
    public function filters(Request $request): array
    {
        $stages = collect(explode(',', (string) $request->input('status', '')))
            ->map(fn ($stage) => $stage === 'disbursed' ? 'paid' : trim($stage)) // older screens said "disbursed"
            ->filter(fn ($stage) => isset(self::STAGES[$stage]))
            ->unique()->values()->all();

        $month = fn (?string $value) => preg_match('/^\d{4}-\d{2}$/', (string) $value) ? $value : null;
        $date = fn (?string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null;

        return array_filter([
            'status' => $stages,
            'search' => trim((string) $request->input('search', '')) ?: null,
            'department_id' => $request->integer('department_id') ?: null,
            'employee_id' => $request->integer('employee_id') ?: null,
            'payment_method' => in_array($request->input('payment_method'), ['bank', 'mobile_money', 'cheque', 'cash'], true) ? $request->input('payment_method') : null,
            'month_from' => $month($request->input('month_from')),
            'month_to' => $month($request->input('month_to')),
            'submitted_from' => $date($request->input('submitted_from')),
            'submitted_to' => $date($request->input('submitted_to')),
        ], fn ($value) => $value !== null && $value !== []);
    }

    public function query(array $filters): Builder
    {
        $query = SalaryAdvanceRequest::query()
            ->with(['employee.department', 'ledgers', 'payment.paymentSource', 'recoveries']);

        if (! empty($filters['status'])) {
            $query->where(function (Builder $any) use ($filters) {
                foreach ($filters['status'] as $stage) {
                    $any->orWhere(fn (Builder $q) => $this->stage($q, $stage));
                }
            });
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('reason', 'like', $term)
                ->orWhereHas('employee', fn (Builder $e) => $e
                    ->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term])
                    ->orWhere('employee_id', 'like', $term)->orWhere('id_number', 'like', $term)));
        }
        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn (Builder $e) => $e->where('department_id', $filters['department_id']));
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['payment_method'])) {
            $query->whereHas('employee', fn (Builder $e) => $e->where('payment_method', $filters['payment_method']));
        }
        if (! empty($filters['month_from'])) {
            $query->where('target_payroll_month', '>=', $filters['month_from']);
        }
        if (! empty($filters['month_to'])) {
            $query->where('target_payroll_month', '<=', $filters['month_to']);
        }
        if (! empty($filters['submitted_from'])) {
            $query->whereDate('created_at', '>=', $filters['submitted_from']);
        }
        if (! empty($filters['submitted_to'])) {
            $query->whereDate('created_at', '<=', $filters['submitted_to']);
        }

        return $query->latest()->orderByDesc('id');
    }

    private function stage(Builder $query, string $stage): void
    {
        match ($stage) {
            'pending' => $query->where('status', 'pending'),
            'approved' => $query->where('status', 'approved')->whereNull('payment_id'),
            'paid' => $query->whereNotNull('payment_id'),
            'outstanding' => $query->whereNotNull('payment_id')->whereColumn('amount_recovered', '<', 'amount'),
            'recovered' => $query->whereNotNull('payment_id')->whereColumn('amount_recovered', '>=', 'amount'),
            'rejected' => $query->where('status', 'rejected'),
        };
    }

    /** Totals for exactly the filtered set. */
    public function summary(array $filters): array
    {
        $rows = $this->query($filters)->reorder()->get(['id', 'amount', 'amount_recovered', 'payment_id', 'status']);
        $awaiting = $rows->filter(fn ($row) => $row->status === 'approved' && ! $row->payment_id);
        $paid = $rows->filter(fn ($row) => $row->payment_id);

        return [
            'count' => $rows->count(),
            'amount' => round((float) $rows->sum('amount'), 2),
            'awaiting_payout_count' => $awaiting->count(),
            'awaiting_payout_amount' => round((float) $awaiting->sum('amount'), 2),
            'paid_amount' => round((float) $paid->sum('amount'), 2),
            'outstanding_amount' => round((float) $paid->sum(fn ($row) => max(0, (float) $row->amount - (float) $row->amount_recovered)), 2),
        ];
    }

    /**
     * One flat, export-ready row per advance, with who decided and how it is repaid.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters): Collection
    {
        $advances = $this->query($filters)->get();
        $decisions = HRAuditLog::query()
            ->with('user:id,name,employee_id')
            ->where('model_type', 'SalaryAdvanceRequest')
            ->whereIn('model_id', $advances->pluck('id'))
            ->whereIn('action', ['salary_advance_approved', 'salary_advance_rejected'])
            ->orderBy('id')
            ->get()
            ->keyBy('model_id');

        return $advances->map(function (SalaryAdvanceRequest $advance) use ($decisions) {
            $employee = $advance->employee;
            $decision = $decisions->get($advance->id);
            $context = $decision?->context ?? [];
            $installment = (float) ($context['monthly_installment'] ?? 0);
            $amount = (float) $advance->amount;
            $salary = (float) ($employee?->salary ?? 0);

            return [
                'reference' => self::reference($advance),
                'submitted_on' => $advance->created_at?->toDateString(),
                'staff_no' => $employee?->employee_id,
                'employee' => trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')) ?: 'Unknown employee',
                'id_number' => $employee?->id_number,
                'kra_pin' => $employee?->kra_pin,
                'department' => $employee?->department?->name,
                'position' => $employee?->position,
                'phone' => $employee?->phone,
                'monthly_salary' => $salary ?: null,
                'amount' => $amount,
                'percent_of_salary' => $salary > 0 ? round($amount / $salary, 4) : null,
                'target_month' => $advance->target_payroll_month,
                'repayment' => ! empty($context['split_installments']) && $installment > 0
                    ? 'KES '.number_format($installment, 2).'/month × '.(int) ceil($amount / $installment)
                    : 'Single deduction in '.$advance->target_payroll_month,
                'reason' => $advance->reason,
                'stage' => self::STAGES[self::stageOf($advance)] ?? $advance->status,
                'stage_key' => self::stageOf($advance),
                'decided_by' => $decision?->user?->name,
                'decided_on' => $decision?->created_at?->toDateString(),
                'hr_remarks' => $advance->hr_remarks,
                'payment_method' => $employee?->payment_method,
                'bank_name' => $employee?->bank_name,
                'bank_code' => $employee?->bank_code,
                'bank_branch' => $employee?->bank_branch,
                'account_number' => $employee?->account_number,
                'paid_on' => $advance->paid_at?->toDateString(),
                'paid_from' => $advance->payment?->paymentSource?->name,
                'payment_reference' => $advance->payment?->external_reference ?: $advance->payment?->receipt_number,
                'recovered' => (float) $advance->amount_recovered,
                'outstanding' => $advance->payment_id ? max(0, $amount - (float) $advance->amount_recovered) : null,
                'missing_payment_details' => self::missingPaymentDetails($employee),
                'self_approved' => $decision && $decision->action === 'salary_advance_approved'
                    && $decision->user?->employee_id && (int) $decision->user->employee_id === (int) $advance->employee_id,
                'checks' => implode('; ', array_filter([
                    $decision && $decision->action === 'salary_advance_approved' && $decision->user?->employee_id
                        && (int) $decision->user->employee_id === (int) $advance->employee_id ? 'Approved by the applicant' : null,
                    self::missingPaymentDetails($employee),
                ])) ?: null,
            ];
        });
    }

    public static function reference(SalaryAdvanceRequest $advance): string
    {
        return 'ADV-'.str_pad((string) $advance->id, 5, '0', STR_PAD_LEFT);
    }

    public static function stageOf(SalaryAdvanceRequest $advance): string
    {
        if ($advance->status === 'rejected') return 'rejected';
        if ($advance->status === 'pending') return 'pending';
        if (! $advance->payment_id) return 'approved';

        return (float) $advance->amount_recovered >= (float) $advance->amount ? 'recovered' : 'outstanding';
    }

    /** What Finance would need and does not have, in plain words. */
    public static function missingPaymentDetails($employee): ?string
    {
        if (! $employee) return 'No employee record';
        $missing = match ($employee->payment_method) {
            'mobile_money' => blank($employee->phone) ? ['phone number'] : [],
            'cheque', 'cash' => [],
            default => array_keys(array_filter([
                'bank name' => blank($employee->bank_name),
                'account number' => blank($employee->account_number),
            ])),
        };

        return $missing ? 'Missing '.implode(' and ', $missing) : null;
    }
}
