<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostCollectorService;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashSurrenderItem;

/**
 * The project actual cost of accepted surrender items.
 *
 * STAB-7: a requisition's actual cost lands at surrender, not at payment, and
 * each accepted item becomes one CostLine keyed to that item — so it can reach
 * the cost collector exactly once however the surrender is staged. The lines
 * do not post their own journals: the surrender's clearing entry is their one
 * posting.
 *
 * One path for every surrender, whole-requisition or by receiver (Report 75R-B).
 */
final class SurrenderCostPoster
{
    public function __construct(private readonly CostCollectorService $collector)
    {
    }

    /**
     * @param  iterable<PettyCashSurrenderItem>  $items
     * @return int the number of cost lines linked
     */
    public function post(PettyCashRequisition $requisition, iterable $items, string $incurredAt, ?string $payeeName): int
    {
        $enquiry = $requisition->enquiry ?? $requisition->project?->enquiry;
        if (! $enquiry) {
            return 0;   // office spend carries no project cost object
        }

        // The budget-exception justification travels with the cost, or an
        // authorised over-budget spend would post with no recorded reason.
        $exception = $requisition->budget_exception ?: [];
        $posted = 0;

        foreach ($items as $item) {
            $costLine = $this->collector->postFromSource(
                new CostContext(
                    expenseCode: (string) ($item->expenseCode?->code ?? ''),
                    amount: (string) $item->amount,
                    nature: CostLine::NATURE_ACTUAL,
                    enquiryId: $enquiry->id,
                    jobNumber: $enquiry->job_number,
                    sourceType: PettyCashSurrenderItem::class,
                    sourceId: $item->id,
                    taxAmount: (string) $item->tax_amount,
                    incurredAt: $incurredAt,
                    payeeName: $item->supplier_name ?: $payeeName,
                    description: $item->description,
                    details: array_filter([
                        'receipt_type' => $item->receipt_type,
                        'receipt_number' => $item->receipt_number,
                        'supplier_kra_pin' => $item->supplier_kra_pin,
                        'requisition_id' => $requisition->id,
                        'requisition_number' => $requisition->requisition_number,
                        'venue' => $requisition->venue,
                        'unbudgeted_reason' => $exception['reason'] ?? null,
                        'budget_exception_log_id' => $exception['governance_log_id'] ?? null,
                    ]),
                    // Project-cost attribution only — the clearing entry is the posting.
                    postsIndependently: false,
                ),
                ['site' => $requisition->venue]
            );

            $item->update(['cost_line_id' => $costLine->id]);
            $posted++;
        }

        return $posted;
    }
}
