<?php

namespace App\Modules\Finance\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Services\CostAccountService;
use App\Modules\Projects\Services\FinanceService;
use Illuminate\Support\Facades\DB;

/**
 * W1-3/W1-4: the explicit, distinguished client/project financial figures —
 * replacing one ambiguous "Client Outstanding" with the confirmed set.
 *
 * Every figure here is read from an existing, authoritative calculation
 * (`FinanceService::getPaymentProgress()`, `CostAccountService::forEnquiry()`,
 * or a direct sum over `project_invoices`/`project_invoice_allocations`,
 * which are themselves the tables those two services already trust) — this
 * class does not introduce a second, competing definition of any of them.
 */
class ClientFinancialPositionService
{
    public function __construct(
        private FinanceService $financeService,
        private CostAccountService $costAccountService,
    ) {}

    public function forEnquiry(ProjectEnquiry $enquiry): array
    {
        $progress = $this->financeService->getPaymentProgress($enquiry);

        $approvedQuoteValue = (float) $progress['total_quote'];
        $cashReceived = (float) $progress['total_paid'];

        // Net of any non-void credit note — the same "what this invoice is
        // really worth now" definition ProjectInvoice::scopeWithNetTotal()
        // already uses, summed across the whole project rather than one
        // invoice.
        $amountInvoiced = (float) (DB::table('project_invoices')
            ->where('project_enquiry_id', $enquiry->id)
            ->where('status', '!=', 'void')
            ->sum('total_amount') ?: 0);

        $projectRevenue = (float) (DB::table('project_invoices')
            ->where('project_enquiry_id', $enquiry->id)
            ->where('status', '!=', 'void')
            ->sum('subtotal') ?: 0);

        $amountAllocated = (float) (DB::table('project_invoice_allocations')
            ->join('project_invoices', 'project_invoices.id', '=', 'project_invoice_allocations.project_invoice_id')
            ->where('project_invoices.project_enquiry_id', $enquiry->id)
            ->sum('project_invoice_allocations.amount') ?: 0);

        $invoiceOutstanding = max(0.0, $amountInvoiced - $amountAllocated);
        $remainingToInvoice = max(0.0, $approvedQuoteValue - $amountInvoiced);
        $unallocatedClientCredit = max(0.0, $cashReceived - $amountAllocated);

        $margin = $this->costAccountService->forEnquiry($enquiry)['margin'] ?? null;

        return [
            'approved_quote_value' => $approvedQuoteValue,
            'amount_invoiced' => $amountInvoiced,
            'remaining_to_invoice' => $remainingToInvoice,
            'cash_received' => $cashReceived,
            'amount_allocated' => $amountAllocated,
            'invoice_outstanding' => $invoiceOutstanding,
            'unallocated_client_credit' => $unallocatedClientCredit,
            'unallocated_client_credit_age_days' => $unallocatedClientCredit > 0
                ? $this->oldestUnallocatedReceiptAgeDays($enquiry)
                : null,
            'project_revenue' => $projectRevenue,
            'project_cost' => [
                // Direct only — W6-1's confirmed distinction. Sourced from the
                // same cost-of-sales figure CostAccountService already
                // computes and trusts; not a second calculation.
                'direct' => (float) ($margin['cost_of_sales'] ?? 0),
                'basis' => $margin['cost_basis'] ?? 'actual',
            ],
            'project_margin' => [
                'direct' => (float) ($margin['margin'] ?? 0),
                'direct_percent' => $margin['margin_percent'] ?? null,
                // W6-1's reliability rule: never presented as available.
                'fully_loaded_available' => false,
                'fully_loaded_note' => 'Fully Loaded Margin is a confirmed future target (W6-1), '
                    . 'not yet reliable — it depends on overhead allocation (W6-1A), labour '
                    . 'costing (Workflow 7), and logistics/fleet costing (Workflow 8), none of '
                    . 'which is implemented yet.',
            ],
        ];
    }

    /**
     * Oldest receipt still carrying an unallocated balance for this enquiry,
     * in days — the age signal W1-9 asks for, without inventing an
     * escalation threshold (that remains Finance's to confirm).
     */
    private function oldestUnallocatedReceiptAgeDays(ProjectEnquiry $enquiry): ?int
    {
        $oldest = DB::table('enquiry_payments as ep')
            ->leftJoin('project_invoice_allocations as a', 'a.enquiry_payment_id', '=', 'ep.id')
            ->where('ep.project_enquiry_id', $enquiry->id)
            ->where('ep.status', 'verified')
            ->whereNull('ep.reversed_at')
            ->groupBy('ep.id', 'ep.amount', 'ep.payment_date')
            ->havingRaw('ep.amount > COALESCE(SUM(a.amount), 0)')
            ->orderBy('ep.payment_date')
            ->value('ep.payment_date');

        return $oldest ? abs((int) now()->diffInDays(\Illuminate\Support\Carbon::parse($oldest))) : null;
    }
}
