<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\EnquiryPayment;
use App\Models\TaskQuoteData;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    private const MOBILIZATION_THRESHOLD_PERCENTAGE = 70;

    /**
     * Log a new payment against an enquiry
     */
    public function logPayment(ProjectEnquiry $enquiry, array $data): EnquiryPayment
    {
        $quote = $this->resolveQuoteBasis($enquiry);
        if ($quote['amount'] <= 0) {
            throw new \DomainException('A quote must be approved before payments can be recorded.');
        }

        return DB::transaction(function () use ($enquiry, $data) {
            $payment = EnquiryPayment::create([
                'project_enquiry_id' => $enquiry->id,
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'] ?? now(),
                'payment_method' => $data['payment_method'] ?? null,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'recorded_by' => Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            return $payment;
        });
    }

    /**
     * Update an existing payment
     */
    public function updatePayment(EnquiryPayment $payment, array $data, string $reason): EnquiryPayment
    {
        return DB::transaction(function () use ($payment, $data, $reason) {
            $oldAmount = $payment->amount;
            $payment->update([
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'] ?? $payment->payment_date,
                'payment_method' => $data['payment_method'] ?? $payment->payment_method,
                'transaction_reference' => $data['transaction_reference'] ?? $payment->transaction_reference,
                'notes' => ($payment->notes ? $payment->notes . "\n" : "") . "Updated on " . now()->format('d M Y') . ": $reason",
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
        return DB::transaction(function () use ($payment, $reason) {
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $payment->project_enquiry_id,
                'user_id' => Auth::id(),
                'gate_type' => 'Financial Deletion',
                'action_status' => 'authorized',
                'model_type' => EnquiryPayment::class,
                'model_id' => $payment->id,
                'message' => "Payment of {$payment->amount} deleted",
                'context' => ['reason' => $reason, 'deleted_amount' => $payment->amount],
                'ip_address' => request()->ip()
            ]);

            return $payment->delete();
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
        $payments = $enquiry->relationLoaded('payments') ? $enquiry->payments : null;
        $totalPaid = $payments ? $payments->sum('amount') : $enquiry->payments()->sum('amount');
        $paymentCount = $payments ? $payments->count() : $enquiry->payments()->count();
        $latestPaymentValue = $payments
            ? $payments->sortByDesc('payment_date')->first()?->payment_date
            : $enquiry->payments()->latest('payment_date')->value('payment_date');
        $latestPaymentDate = $latestPaymentValue
            ? \Illuminate\Support\Carbon::parse($latestPaymentValue)->toDateString()
            : null;

        $percentage = $totalQuoteAmount > 0 ? ($totalPaid / $totalQuoteAmount) * 100 : 0;
        $thresholdAmount = $totalQuoteAmount * (self::MOBILIZATION_THRESHOLD_PERCENTAGE / 100);

        return [
            'total_quote' => (float)$totalQuoteAmount,
            'total_paid' => (float)$totalPaid,
            'remaining' => (float)max(0, $totalQuoteAmount - $totalPaid),
            'percentage' => round($percentage, 2),
            'threshold_percentage' => self::MOBILIZATION_THRESHOLD_PERCENTAGE,
            'threshold_amount' => round($thresholdAmount, 2),
            'amount_required_for_threshold' => (float) max(0, $thresholdAmount - $totalPaid),
            'is_70_percent_met' => $percentage >= self::MOBILIZATION_THRESHOLD_PERCENTAGE,
            'is_threshold_met' => $percentage >= self::MOBILIZATION_THRESHOLD_PERCENTAGE,
            'is_client_approved_basis' => in_array($quoteBasis, ['approved_snapshot', 'client_approved_quote'], true),
            'has_approved_quote' => $totalQuoteAmount > 0,
            'quote_basis' => $quoteBasis,
            'quote_source_label' => $quote['source_label'],
            'quote_approval' => $quote['approval'],
            'client_approved_amount' => (float)$enquiry->client_approved_quote,
            'payment_count' => $paymentCount,
            'latest_payment_date' => $latestPaymentDate,
            'is_overpaid' => $totalPaid > $totalQuoteAmount && $totalQuoteAmount > 0,
            'overpaid_amount' => (float) max(0, $totalPaid - $totalQuoteAmount),
            'finance_released' => (bool) ($enquiry->finance_released ?? false),
            'finance_released_at' => optional($enquiry->finance_released_at)->toISOString(),
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

        if ($approval) {
            if ($approval->approval_status !== 'approved') {
                return [
                    'amount' => 0.0,
                    'basis' => 'none',
                    'source_label' => 'Quote approval pending',
                    'approval' => null,
                ];
            }

            if ((float) $approval->quote_amount <= 0) {
                return [
                    'amount' => 0.0,
                    'basis' => 'none',
                    'source_label' => 'Approved quote has no value',
                    'approval' => null,
                ];
            }

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
            ];
        }

        $clientApprovedQuote = (float) ($enquiry->client_approved_quote ?? 0);

        if ($clientApprovedQuote > 0) {
            return [
                'amount' => $clientApprovedQuote,
                'basis' => 'client_approved_quote',
                'source_label' => 'Approved quote (legacy)',
                'approval' => null,
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
            ];
        }

        return [
            'amount' => 0.0,
            'basis' => 'none',
            'source_label' => 'No approved quote',
            'approval' => null,
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
