<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\EnquiryPayment;
use App\Models\TaskQuoteData;
use App\Constants\EnquiryConstants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinanceService
{
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
     * Manually release an enquiry for production
     */
    public function releaseProject(ProjectEnquiry $enquiry, ?string $notes = null): bool
    {
        if ($enquiry->status !== EnquiryConstants::STATUS_AWAITING_DEPOSIT && $enquiry->status !== EnquiryConstants::STATUS_QUOTE_APPROVED) {
            // Can only release if it's waiting for deposit or already quote approved (re-release)
        }

        return $enquiry->update([
            'status' => EnquiryConstants::STATUS_PLANNING, // Move to planning
            'follow_up_notes' => ($enquiry->follow_up_notes ? $enquiry->follow_up_notes . "\n" : "") . "Manually Released for Production on " . now()->format('d M Y') . ($notes ? ": $notes" : "")
        ]);
    }

    /**
     * Get the payment progress percentage
     */
    public function getPaymentProgress(ProjectEnquiry $enquiry): array
    {
        // Prioritize the manually entered Client Approved Quote
        $totalQuoteAmount = $enquiry->client_approved_quote;
        $isClientApproved = true;

        // Fallback to the system-generated quote if no client quote is entered
        if ($totalQuoteAmount === null || $totalQuoteAmount <= 0) {
            $quoteData = TaskQuoteData::whereHas('enquiryTask', function ($query) use ($enquiry) {
                $query->where('project_enquiry_id', $enquiry->id);
            })->first();
            
            $totalQuoteAmount = $quoteData ? $quoteData->quote_amount : 0;
            $isClientApproved = false;
        }

        $totalPaid = $enquiry->payments()->sum('amount');
        $percentage = $totalQuoteAmount > 0 ? ($totalPaid / $totalQuoteAmount) * 100 : 0;

        return [
            'total_quote' => (float)$totalQuoteAmount,
            'total_paid' => (float)$totalPaid,
            'remaining' => (float)max(0, $totalQuoteAmount - $totalPaid),
            'percentage' => round($percentage, 2),
            'is_70_percent_met' => $percentage >= 70,
            'is_client_approved_basis' => $isClientApproved,
            'client_approved_amount' => (float)$enquiry->client_approved_quote
        ];
    }
}
