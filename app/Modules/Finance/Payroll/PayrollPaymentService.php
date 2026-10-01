<?php

namespace App\Modules\Finance\Payroll;

use App\Modules\Finance\Models\PaymentSource;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Services\Payroll\PayrollFinancePostingService;
use App\Modules\Notifications\Services\NotificationService;
use App\Support\SelfApproval;
use Illuminate\Support\Facades\DB;

/**
 * Paying a locked payroll run: the one write path (Report 67).
 *
 * HR's `payroll/runs/{run}/mark-paid` and Finance's `finance/payroll/runs/{run}/pay`
 * both come here, so segregation, locking, posting, audit and the employees'
 * payslip notices are defined once. Posting is PayrollFinancePostingService's:
 * a real Payment through PaymentSettlementService (idempotency key
 * payroll-run:{id}) and the journal that relieves Net Payroll Payable. It never
 * recognises salary expense again; that happened once, when the run was locked.
 */
class PayrollPaymentService
{
    public function __construct(private PayrollFinancePostingService $posting) {}

    /**
     * Why this user may not pay this run now, or null when they may.
     * The same rules the payment enforces; screens show the reason.
     */
    public function refusal(PayrollRun $run, int $actorId): ?string
    {
        if ($run->status !== 'locked') {
            return $run->status === 'paid' ? 'This payroll run is already paid.' : 'Only a locked payroll run can be paid.';
        }
        if (! $run->accrual_journal_entry_id) {
            return 'This payroll run has no accrual journal, so it cannot be paid from Finance.';
        }
        if ($run->locked_by !== null && (int) $run->locked_by === $actorId && ! SelfApproval::isPermittedSelfApproval($actorId, $run->locked_by)) {
            return 'The person who locked this payroll run cannot also pay it.';
        }

        return null;
    }

    /** @throws \DomainException with the refusal a person can act on */
    public function pay(PayrollRun $run, PaymentSource $source, string $date, string $reference, int $actorId): PayrollRun
    {
        if ($refusal = $this->refusal($run, $actorId)) {
            throw new \DomainException($refusal);
        }
        $isSelfApproval = SelfApproval::isPermittedSelfApproval($actorId, $run->locked_by);

        DB::transaction(function () use ($run, $source, $date, $reference, $actorId) {
            $locked = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'locked') {
                throw new \DomainException($locked->status === 'paid' ? 'This payroll run is already paid.' : 'Only a locked payroll run can be paid.');
            }
            $this->posting->postPayment($locked, $source, $date, $reference);
            $locked->update(['status' => 'paid', 'paid_by' => $actorId]);
            $locked->payslips()->update(['status' => 'paid', 'payment_date' => $date]);
        });
        $run->refresh();

        HRAuditLog::create([
            'user_id' => $actorId,
            'action' => 'payroll_run_paid',
            'model_type' => 'PayrollRun',
            'model_id' => $run->id,
            'message' => "Payroll run for {$run->payroll_month} marked as PAID.",
            'context' => [
                'payroll_month' => $run->payroll_month,
                'payment_date' => $run->payment_date?->toDateString(),
                'payment_reference' => $run->payment_reference,
                'payment_journal_entry_id' => $run->payment_journal_entry_id,
                'self_approval_override' => $isSelfApproval,
            ],
            'ip_address' => request()->ip(),
        ]);

        // Each paid employee is told their own payslip is ready.
        foreach ($run->payslips()->with('employee.user')->get() as $payslip) {
            $recipient = $payslip->employee?->user;
            if (! $recipient) {
                continue;
            }
            NotificationService::send(
                type: 'payroll_payslip_ready',
                title: 'Payslip Ready',
                message: "Your payslip for {$run->payroll_month} is ready. Net pay: KES ".number_format($payslip->net_pay, 2),
                module: 'hr',
                data: ['payroll_run_id' => $run->id, 'payslip_id' => $payslip->id, 'url' => "/self-service/payslips/{$payslip->id}"],
                users: [$recipient],
            );
        }

        return $run;
    }
}
