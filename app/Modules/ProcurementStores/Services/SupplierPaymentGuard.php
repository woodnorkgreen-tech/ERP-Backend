<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Bill;
use RuntimeException;

/*
 * The one gate every shilling paid to a supplier passes through.
 *
 * Three places create a BillPayment — the single-bill screen, the batch payment
 * run, and a petty cash disbursement against a linked bill — and each used to
 * decide for itself what was payable, which in practice meant none of them
 * decided anything. The rule now lives here and is enforced from
 * BillPayment::creating, so a new payment path inherits the control instead of
 * having to remember it.
 */
class SupplierPaymentGuard
{
    public function __construct(private PurchaseOrderWorkflow $workflow) {}

    /** @return array{payable:bool, blockers:array<int,string>} */
    public function evaluate(Bill $bill): array
    {
        $state = $this->workflow->bill($bill);

        return [
            'payable' => (bool) $state['can_pay'],
            'blockers' => $state['blockers'],
            'stage' => $state['stage'],
        ];
    }

    /**
     * @throws RuntimeException naming what the payer must fix, in the words the
     *                          screens already use, so the API message and the
     *                          checklist on the page never read differently.
     */
    public function assertPayable(Bill $bill, string $amount): void
    {
        if (bccomp((string) $amount, (string) $bill->balance, 2) > 0) {
            throw new RuntimeException(sprintf(
                'Payment of %s exceeds the %s outstanding on invoice %s.',
                number_format((float) $amount, 2),
                number_format((float) $bill->balance, 2),
                $bill->bill_number
            ));
        }

        $result = $this->evaluate($bill);
        if ($result['payable']) {
            return;
        }

        throw new RuntimeException(
            'Invoice '.$bill->bill_number.' is not cleared for payment: '.implode(' ', $result['blockers'])
        );
    }
}
