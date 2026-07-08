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
        [$totalQuoteAmount, $quoteBasis] = $this->resolveQuoteBasis($enquiry);

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
            'is_client_approved_basis' => $quoteBasis === 'client_approved_quote',
            'quote_basis' => $quoteBasis,
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
        $clientApprovedQuote = (float) ($enquiry->client_approved_quote ?? 0);

        if ($clientApprovedQuote > 0) {
            return [$clientApprovedQuote, 'client_approved_quote'];
        }

        // Fall back to APPROVED quote data only. Payment progress and the 70%
        // mobilization gate must never be measured against an unapproved draft.
        $quoteData = $this->loadedApprovedQuoteData($enquiry) ?? $this->latestApprovedQuoteData($enquiry);

        if ($quoteData && (float) $quoteData->quote_amount > 0) {
            return [(float) $quoteData->quote_amount, 'system_quote'];
        }

        return [0.0, 'none'];
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
