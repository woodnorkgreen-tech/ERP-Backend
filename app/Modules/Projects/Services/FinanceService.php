<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\EnquiryPayment;
use App\Models\TaskQuoteData;
use App\Modules\Finance\Models\ClientReceipt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    private const DEFAULT_MOBILIZATION_THRESHOLD_PERCENTAGE = 70;

    /**
     * Log a new payment against an enquiry
     */
    public function logPayment(ProjectEnquiry $enquiry, array $data): EnquiryPayment
    {
        $quote = $this->resolveQuoteBasis($enquiry);
        if ($quote['amount'] <= 0) {
            throw new \DomainException($quote['waived']
                ? 'Enter the quote amount in Project Billing before recording payments.'
                : 'A quote must be approved or formally waived before payments can be recorded.');
        }

        return DB::transaction(function () use ($enquiry, $data) {
            $reference = trim((string) ($data['transaction_reference'] ?? ''));
            if ($reference === '') {
                $reference = 'CASH-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 8));
            }

            $receipt = ClientReceipt::query()
                ->where('payment_source_id', $data['payment_source_id'])
                ->where('transaction_reference', $reference)
                ->lockForUpdate()
                ->first();

            if (!$receipt) {
                $receipt = ClientReceipt::create([
                    'payment_source_id' => $data['payment_source_id'],
                    'received_amount' => $data['received_amount'],
                    'payment_date' => $data['payment_date'] ?? now(),
                    'payment_method' => $data['payment_method'],
                    'transaction_reference' => $reference,
                    'evidence_path' => $data['evidence_path'] ?? null,
                    'recorded_by' => Auth::id(),
                ]);
            } elseif ((float) $receipt->received_amount !== (float) $data['received_amount']) {
                throw new \DomainException('This transaction reference already exists with a different receipt total. Use the original total when allocating it.');
            }

            $allocated = (float) $receipt->allocations()
                ->whereNull('reversed_at')->sum('amount');
            if ($allocated + (float) $data['amount'] > (float) $receipt->received_amount) {
                $available = max(0, (float) $receipt->received_amount - $allocated);
                throw new \DomainException("This allocation exceeds the unallocated receipt balance of KES " . number_format($available, 2) . '.');
            }

            $payment = EnquiryPayment::create([
                'project_enquiry_id' => $enquiry->id,
                'client_receipt_id' => $receipt->id,
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_source_id' => $data['payment_source_id'],
                'transaction_reference' => $reference,
                'evidence_path' => $receipt->evidence_path,
                'recorded_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            return $payment;
        });
    }

    public function verifyPayment(EnquiryPayment $payment, int $userId): EnquiryPayment
    {
        if ($payment->reversed_at || $payment->status === 'reversed') {
            throw new \DomainException('A reversed receipt cannot be verified.');
        }
        if ($payment->status === 'verified') {
            throw new \DomainException('This receipt has already been verified.');
        }
        // Separation of duties: normally the person who recorded the money may
        // not be the one who confirms it arrived. Holders of
        // APPROVALS_SELF_APPROVE (and Super Admin, via the global Gate bypass)
        // are the documented exception — the self-approval is still recorded
        // below rather than passing silently.
        $isSelfVerification = (int) $payment->recorded_by === $userId;

        if ($isSelfVerification && ! \App\Support\SelfApproval::allowedFor($userId)) {
            throw new \DomainException(
                'This receipt was recorded by you, so someone else has to confirm it. '
                . 'If nobody else is available, an administrator can grant the "Approve Your Own Submissions" permission.'
            );
        }

        return DB::transaction(function () use ($payment, $userId, $isSelfVerification) {
            $payment->update(['status' => 'verified', 'verified_at' => now(), 'verified_by' => $userId]);
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $payment->project_enquiry_id,
                'user_id' => $userId,
                'gate_type' => $isSelfVerification ? 'Receipt Verification (self-approved)' : 'Receipt Verification',
                'action_status' => 'authorized',
                'model_type' => EnquiryPayment::class,
                'model_id' => $payment->id,
                'message' => $isSelfVerification
                    ? "Receipt of {$payment->amount} verified by the same user who recorded it"
                    : "Receipt of {$payment->amount} verified",
                'context' => ['recorded_by' => $payment->recorded_by, 'self_approved' => $isSelfVerification],
                'ip_address' => request()->ip(),
            ]);
            return $payment->fresh(['recorder', 'verifier']);
        });
    }

    public function allocateReceipt(ClientReceipt $receipt, ProjectEnquiry $enquiry, array $data): EnquiryPayment
    {
        $quote = $this->resolveQuoteBasis($enquiry);
        if ($quote['amount'] <= 0) {
            throw new \DomainException('The target project requires an approved billing basis before receipt allocation.');
        }

        return DB::transaction(function () use ($receipt, $enquiry, $data) {
            $lockedReceipt = ClientReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $allocated = (float) $lockedReceipt->allocations()->whereNull('reversed_at')->sum('amount');
            $available = max(0, (float) $lockedReceipt->received_amount - $allocated);
            if ((float) $data['amount'] > $available) {
                throw new \DomainException('Allocation exceeds the available receipt balance of KES ' . number_format($available, 2) . '.');
            }

            return EnquiryPayment::create([
                'project_enquiry_id' => $enquiry->id,
                'client_receipt_id' => $lockedReceipt->id,
                'amount' => $data['amount'],
                'payment_date' => $lockedReceipt->payment_date,
                'payment_method' => $lockedReceipt->payment_method,
                'payment_source_id' => $lockedReceipt->payment_source_id,
                'transaction_reference' => $lockedReceipt->transaction_reference,
                'evidence_path' => $lockedReceipt->evidence_path,
                'recorded_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Update an existing payment
     */
    public function updatePayment(EnquiryPayment $payment, array $data, string $reason): EnquiryPayment
    {
        if ($payment->reversed_at) {
            throw new \DomainException('A reversed receipt cannot be edited. Record a new receipt instead.');
        }

        return DB::transaction(function () use ($payment, $data, $reason) {
            if ($payment->client_receipt_id) {
                $receipt = ClientReceipt::query()->lockForUpdate()->findOrFail($payment->client_receipt_id);
                $otherAllocations = (float) $receipt->allocations()
                    ->whereKeyNot($payment->id)->whereNull('reversed_at')->sum('amount');
                if ($otherAllocations + (float) $data['amount'] > (float) $receipt->received_amount) {
                    $available = max(0, (float) $receipt->received_amount - $otherAllocations);
                    throw new \DomainException('The corrected allocation exceeds the available receipt balance of KES ' . number_format($available, 2) . '.');
                }
            }
            $oldAmount = $payment->amount;
            $payment->update([
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'] ?? $payment->payment_date,
                'payment_method' => $data['payment_method'] ?? $payment->payment_method,
                'transaction_reference' => $data['transaction_reference'] ?? $payment->transaction_reference,
                'notes' => ($payment->notes ? $payment->notes . "\n" : "") . "Updated on " . now()->format('d M Y') . ": $reason",
                'status' => 'pending',
                'verified_at' => null,
                'verified_by' => null,
            ]);

            // Log governance if amount changed
            if ((float)$oldAmount !== (float)$data['amount']) {
                \App\Models\GovernanceAuditLog::create([
                    'project_enquiry_id' => $payment->project_enquiry_id,
                    'user_id' => Auth::id(),
                    'gate_type' => 'Financial Correction',
                    'action_status' => 'authorized',
                    'model_type' => EnquiryPayment::class,
                    'model_id' => $payment->id,
                    'message' => "Payment amount corrected from $oldAmount to {$data['amount']}",
                    'context' => ['reason' => $reason, 'old_amount' => $oldAmount, 'new_amount' => $data['amount']],
                    'ip_address' => request()->ip()
                ]);
            }

            return $payment;
        });
    }

    /**
     * Delete a payment
     */
    public function deletePayment(EnquiryPayment $payment, string $reason): bool
    {
        if ($payment->reversed_at) {
            throw new \DomainException('This receipt has already been reversed.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $payment->project_enquiry_id,
                'user_id' => Auth::id(),
                'gate_type' => 'Financial Reversal',
                'action_status' => 'authorized',
                'model_type' => EnquiryPayment::class,
                'model_id' => $payment->id,
                'message' => "Receipt of {$payment->amount} reversed",
                'context' => ['reason' => $reason, 'reversed_amount' => $payment->amount],
                'ip_address' => request()->ip()
            ]);

            return $payment->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => Auth::id(),
                'reversal_reason' => $reason,
            ]);
        });
    }

    /**
     * Get the payment progress percentage
     */
    public function getPaymentProgress(ProjectEnquiry $enquiry): array
    {
        $quote = $this->resolveQuoteBasis($enquiry);
        $totalQuoteAmount = $quote['amount'];
        $quoteBasis = $quote['basis'];

        // Use loaded collection when available to avoid N+1 on paginated lists
        $payments = $enquiry->relationLoaded('payments')
            ? $enquiry->payments->whereNull('reversed_at')->where('status', 'verified')
            : null;
        $verifiedQuery = fn () => $enquiry->payments()->whereNull('reversed_at')->where('status', 'verified');
        $totalPaid = $payments ? $payments->sum('amount') : $verifiedQuery()->sum('amount');
        $paymentCount = $payments ? $payments->count() : $verifiedQuery()->count();
        $latestPaymentValue = $payments
            ? $payments->sortByDesc('payment_date')->first()?->payment_date
            : $verifiedQuery()->latest('payment_date')->value('payment_date');
        $pendingPaymentCount = $enquiry->relationLoaded('payments')
            ? $enquiry->payments->whereNull('reversed_at')->where('status', 'pending')->count()
            : $enquiry->payments()->whereNull('reversed_at')->where('status', 'pending')->count();
        $latestPaymentDate = $latestPaymentValue
            ? \Illuminate\Support\Carbon::parse($latestPaymentValue)->toDateString()
            : null;

        $percentage = $totalQuoteAmount > 0 ? ($totalPaid / $totalQuoteAmount) * 100 : 0;
        $thresholdPercentage = (float) ($enquiry->mobilization_threshold_percentage
            ?? self::DEFAULT_MOBILIZATION_THRESHOLD_PERCENTAGE);
        $thresholdAmount = $totalQuoteAmount * ($thresholdPercentage / 100);

        return [
            'total_quote' => (float)$totalQuoteAmount,
            'total_paid' => (float)$totalPaid,
            'remaining' => (float)max(0, $totalQuoteAmount - $totalPaid),
            'percentage' => round($percentage, 2),
            'threshold_percentage' => $thresholdPercentage,
            'threshold_amount' => round($thresholdAmount, 2),
            'amount_required_for_threshold' => (float) max(0, $thresholdAmount - $totalPaid),
            'is_70_percent_met' => $percentage >= $thresholdPercentage,
            'is_threshold_met' => $percentage >= $thresholdPercentage,
            'is_client_approved_basis' => in_array($quoteBasis, ['approved_snapshot', 'client_approved_quote'], true),
            'has_approved_quote' => $totalQuoteAmount > 0 && !$quote['waived'],
            'quote_requirement_waived' => (bool) $quote['waived'],
            'can_process_finance' => $totalQuoteAmount > 0 || (bool) $quote['waived'],
            'can_record_payments' => $totalQuoteAmount > 0,
            'quote_basis' => $quoteBasis,
            'quote_source_label' => $quote['source_label'],
            'quote_approval' => $quote['approval'],
            'client_approved_amount' => (float)$enquiry->client_approved_quote,
            'payment_count' => $paymentCount,
            'pending_payment_count' => $pendingPaymentCount,
            'latest_payment_date' => $latestPaymentDate,
            'is_overpaid' => $totalPaid > $totalQuoteAmount && $totalQuoteAmount > 0,
            'overpaid_amount' => (float) max(0, $totalPaid - $totalQuoteAmount),
            'finance_released' => (bool) ($enquiry->finance_released ?? false),
            'finance_released_at' => optional($enquiry->finance_released_at)->toISOString(),
            'is_post_release_breach' => (bool) ($enquiry->finance_released ?? false)
                && $percentage < $thresholdPercentage,
            'quote_waiver_reason' => $enquiry->quote_waiver_reason,
            'quote_waived_at' => optional($enquiry->quote_waived_at)->toISOString(),
        ];
    }

    private function resolveQuoteBasis(ProjectEnquiry $enquiry): array
    {
        // The approval row is the immutable decision snapshot and therefore the
        // first choice for billing. The enquiry amount is only a denormalized
        // compatibility field and must not outrank an approval-of-record.
        $approval = $enquiry->relationLoaded('quoteApprovals')
            ? $enquiry->quoteApprovals->sortByDesc('updated_at')->first()
            : DB::table('quote_approvals')
                ->where('enquiry_id', $enquiry->id)
                ->latest('updated_at')
                ->first();

        $unapprovedSourceLabel = $approval?->approval_status === 'approved'
            ? 'Approved quote has no value'
            : ($approval ? 'Quote approval pending' : 'No approved quote');

        if ($approval && $approval->approval_status === 'approved' && (float) $approval->quote_amount > 0) {
            return [
                'amount' => (float) $approval->quote_amount,
                'basis' => 'approved_snapshot',
                'source_label' => 'Approved quote',
                'approval' => [
                    'id' => (int) $approval->id,
                    'task_id' => (int) $approval->task_id,
                    'approved_by' => $approval->approved_by,
                    'approved_at' => $approval->approval_date instanceof \DateTimeInterface
                        ? $approval->approval_date->format('Y-m-d')
                        : $approval->approval_date,
                    'recorded_at' => $approval->updated_at instanceof \DateTimeInterface
                        ? $approval->updated_at->format(DATE_ATOM)
                        : $approval->updated_at,
                ],
                'waived' => false,
            ];
        }

        $clientApprovedQuote = (float) ($enquiry->client_approved_quote ?? 0);

        if ($clientApprovedQuote > 0) {
            return [
                'amount' => $clientApprovedQuote,
                'basis' => 'client_approved_quote',
                'source_label' => 'Approved quote (legacy)',
                'approval' => null,
                'waived' => false,
            ];
        }

        // Fall back to APPROVED quote data only. Payment progress and the 70%
        // mobilization gate must never be measured against an unapproved draft.
        $quoteData = $this->loadedApprovedQuoteData($enquiry) ?? $this->latestApprovedQuoteData($enquiry);

        if ($quoteData && (float) $quoteData->quote_amount > 0) {
            return [
                'amount' => (float) $quoteData->quote_amount,
                'basis' => 'system_quote',
                'source_label' => 'Approved quote',
                'approval' => [
                    'id' => null,
                    'task_id' => null,
                    'approved_by' => $quoteData->approved_by,
                    'approved_at' => optional($quoteData->approval_date)->toDateString(),
                    'recorded_at' => optional($quoteData->updated_at)->toISOString(),
                ],
                'waived' => false,
            ];
        }

        if ($enquiry->quote_requirement_waived) {
            return [
                'amount' => (float) ($enquiry->quote_waiver_billing_amount ?? 0),
                'basis' => 'quote_waiver',
                'source_label' => $enquiry->quote_waiver_billing_amount
                    ? 'Quote amount entered in billing'
                    : 'Quote formally waived',
                'approval' => null,
                'waived' => true,
            ];
        }

        return [
            'amount' => 0.0,
            'basis' => 'none',
            'source_label' => $unapprovedSourceLabel,
            'approval' => null,
            'waived' => false,
        ];
    }

    private function loadedApprovedQuoteData(ProjectEnquiry $enquiry): ?TaskQuoteData
    {
        if (!$enquiry->relationLoaded('enquiryTasks')) {
            return null;
        }

        $quoteTask = $enquiry->enquiryTasks
            ->where('type', 'quote')
            ->filter(fn ($task) => $task->relationLoaded('quoteData')
                && $task->quoteData
                && ($task->quoteData->approval_status ?? $task->quoteData->status) === 'approved')
            ->sortByDesc(fn ($task) => optional($task->quoteData->updated_at)->timestamp ?? 0)
            ->first();

        return $quoteTask?->quoteData;
    }

    private function latestApprovedQuoteData(ProjectEnquiry $enquiry): ?TaskQuoteData
    {
        return TaskQuoteData::whereHas('enquiryTask', function ($query) use ($enquiry) {
                $query->where('project_enquiry_id', $enquiry->id)
                    ->where('type', 'quote');
            })
            ->whereRaw("COALESCE(approval_status, status) = 'approved'")
            ->latest('updated_at')
            ->first();
    }
}
