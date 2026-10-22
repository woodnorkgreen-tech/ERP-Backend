<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\PurchaseOrder;

/*
 * One interpretation of "where has this order got to, and may we pay it yet".
 *
 * Procurement, Stores and Accounts each hold one leg of the same purchase, and
 * before this class each screen decided for itself what "received" and
 * "payable" meant. Every screen now reads its stage, its owner and its next
 * action from here, so the purchase order page, the bill page and the payment
 * gate can never disagree about the same order.
 *
 * The money control it encodes is the three-way match: an approved order, an
 * accepted receipt, and a supplier invoice that agrees with both. Quantities
 * carry six decimals because Stores measures in metres and sheets; money
 * carries two. Both are compared with bc* so a part delivery never rounds its
 * way past the gate.
 */
class PurchaseOrderWorkflow
{
    /** Stages, in the order the work actually happens. */
    public const STAGES = ['approval', 'delivery', 'stores', 'invoice', 'verification', 'payment', 'complete'];

    private const STAGE_GUIDE = [
        'approval' => ['Procurement', 'Submit this order for approval, or complete its approval.'],
        'delivery' => ['Procurement', 'Send the approved order to the supplier, then record a goods receipt when it arrives.'],
        'stores' => ['Stores', 'Inspect and confirm the delivery into stock before the invoice can be verified.'],
        'invoice' => ['Accounts', 'Record the supplier invoice against this order.'],
        'verification' => ['Accounts', 'Check the supplier invoice against the approved order and the accepted receipt.'],
        'payment' => ['Accounts', 'The invoice is verified. Record the supplier payment.'],
        'complete' => ['—', 'Settled. The order, receipt, invoice and payment reference are filed together.'],
    ];

    /**
     * A line counts as accepted only once Stores has signed it off. `accepted`
     * is the receiving decision, `store_status` the Stores confirmation, and an
     * `awaiting_*` stock_status means Stores still has work to do on it — see
     * the dual-status invariant on goods_receipt_note_items.
     */
    private function acceptedQuantity($receiptItem): string
    {
        // A reversed receipt received nothing (Report 76A Part 2).
        if (! $receiptItem->accepted || $receiptItem->reversed_at) {
            return '0.000000';
        }
        // A purchase that is not a Stores item is confirmed by its own receipt
        // (GoodsReceiptNoteController::store sets store_status), so it arrives
        // here already `confirmed` and needs no special case.
        if ($receiptItem->store_status !== 'confirmed') {
            return '0.000000';
        }
        if (str_starts_with((string) $receiptItem->stock_status, 'awaiting')) {
            return '0.000000';
        }

        return (string) ($receiptItem->inspection?->accepted_quantity ?? $receiptItem->received_quantity);
    }

    public function order(PurchaseOrder $order): array
    {
        $order->loadMissing([
            'items.material',
            'items.goodsReceiptNoteItems.inspection',
            'goodsReceiptNotes',
            'bills',
            'supplier',
        ]);

        $items = $order->items->map(function ($item) {
            $received = '0.000000';
            $accepted = '0.000000';
            $awaiting = false;

            foreach ($item->goodsReceiptNoteItems as $receiptItem) {
                if ($receiptItem->reversed_at) {
                    continue;
                }
                $received = bcadd($received, (string) $receiptItem->received_quantity, 6);
                $accepted = bcadd($accepted, $this->acceptedQuantity($receiptItem), 6);
                if ($receiptItem->store_status !== 'confirmed' || str_starts_with((string) $receiptItem->stock_status, 'awaiting')) {
                    $awaiting = true;
                }
            }

            $unitPrice = (string) ($item->unit_price ?? '0');

            return [
                'id' => $item->id,
                'name' => $item->material?->material_name ?? $item->custom_description ?? 'Order item',
                'unit' => $item->uom?->code ?? $item->uom?->name,
                'ordered' => (string) $item->quantity,
                'received' => $received,
                'accepted' => $accepted,
                'outstanding' => bcsub((string) $item->quantity, $accepted, 6),
                'unit_price' => $unitPrice,
                'total' => (string) $item->total,
                'accepted_value' => bcmul($accepted, $unitPrice, 2),
                'awaiting_stores' => $awaiting,
                'complete' => bccomp($accepted, (string) $item->quantity, 6) >= 0,
            ];
        });

        $acceptedValue = $items->reduce(fn ($sum, $item) => bcadd($sum, $item['accepted_value'], 2), '0.00');
        $anyAccepted = bccomp($acceptedValue, '0.00', 2) > 0
            || $items->contains(fn ($item) => bccomp($item['accepted'], '0', 6) > 0);
        $receiptComplete = $items->isNotEmpty() && $items->every(fn ($item) => $item['complete']);
        $awaitingStores = $items->contains(fn ($item) => $item['awaiting_stores']);
        $approved = $order->status === 'approved';
        $bill = $order->bills->sortBy('id')->first();
        // W2-3 introduced staged/multiple Bills per order; this single-bill
        // "stage" (see the class docblock) still reflects only the first one
        // raised, kept for the existing screens that read it. But "complete"
        // is a claim of settlement, and closure-gate §16 found it could be
        // reached from the first Bill alone while later ones were still
        // unpaid — a PO showing "Settled" with a real outstanding balance.
        // That specific claim is corrected to require every non-cancelled
        // Bill actually settled; the rest of the single-bill stage sequence
        // is deliberately left as the same known, documented limitation
        // rather than a full multi-bill stage model nobody has confirmed —
        // see 'bills_count' etc. below for the aggregate figures that make
        // the true multi-bill position visible regardless of this stage.
        $validBills = $order->bills->where('status', '!=', 'cancelled');
        $allBillsSettled = $validBills->isNotEmpty()
            && $validBills->every(fn (Bill $b) => bccomp((string) $b->balance, '0.00', 2) <= 0);

        $stage = match (true) {
            ! $approved => 'approval',
            $order->goodsReceiptNotes->isEmpty() => 'delivery',
            ! $anyAccepted || $awaitingStores => 'stores',
            ! $bill => 'invoice',
            default => 'verification',
        };

        if ($bill && $stage === 'verification') {
            if ($allBillsSettled) {
                $stage = 'complete';
            } elseif ($bill->verified_at) {
                $stage = 'payment';
            }
        }

        return [
            'stage' => $stage,
            'stage_index' => array_search($stage, self::STAGES, true),
            'stages' => self::STAGES,
            'owner' => self::STAGE_GUIDE[$stage][0],
            'next_action' => self::STAGE_GUIDE[$stage][1],
            ...$this->position($order, $acceptedValue, $anyAccepted, $receiptComplete, $validBills),
            'order_id' => $order->id,
            'order_number' => $order->po_number,
            'order_approved' => $approved,
            'order_total' => (string) $order->total_amount,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->supplier_name ?? $order->supplier?->name,
            'accepted_value' => $acceptedValue,
            'receipt_started' => $order->goodsReceiptNotes->isNotEmpty(),
            'receipt_complete' => $receiptComplete,
            'awaiting_stores' => $awaitingStores,
            'items' => $items->values()->all(),
            'receipts' => $order->goodsReceiptNotes->map(fn ($receipt) => [
                'id' => $receipt->id,
                'number' => $receipt->grn_number,
                'date' => $receipt->date?->toDateString(),
                'store_status' => $receipt->store_status,
            ])->values()->all(),
            'bill_id' => $bill?->id,
            'bill_number' => $bill?->bill_number,
            // W2-3/closure-gate §16: the true multi-bill position, alongside
            // (never replacing) the single-bill fields above.
            'bills_count' => $validBills->count(),
            'bills_paid_count' => $validBills->filter(fn (Bill $b) => bccomp((string) $b->balance, '0.00', 2) <= 0)->count(),
            'total_billed' => $order->totalBilled(),
            'remaining_billable' => $order->remainingBillable(),
        ];
    }

    /**
     * Received, billed and paid as three separate answers (Report 76A Part 2 §13).
     *
     * The single `stage` above is a sequence, and a sequence cannot say
     * "fully received, part billed, nothing paid". These three can, and none of
     * them implies another: paid is not received, received is not billed,
     * billed is not paid. `exceptions` names what does not reconcile — a price
     * difference on an invoice, or received goods no invoice yet covers.
     *
     * Closure is deliberately absent: an order has no close or cancel action
     * (Report 76 P1-2), so no status here claims one.
     */
    private function position(PurchaseOrder $order, string $acceptedValue, bool $anyAccepted, bool $receiptComplete, $validBills): array
    {
        $covered = $order->totalBilled();
        $receiptStatus = match (true) {
            $order->status !== 'approved' => 'not_ordered',
            $receiptComplete => 'fully_received',
            $anyAccepted => 'partially_received',
            default => 'ordered',
        };
        $billingStatus = match (true) {
            bccomp($covered, '0.00', 2) <= 0 => 'not_billed',
            $receiptComplete && bccomp($covered, (string) $order->total_amount, 2) >= 0 => 'fully_billed',
            default => 'partially_billed',
        };
        $payable = $validBills->reduce(fn ($sum, Bill $b) => bcadd($sum, $b->payableAmount(), 2), '0.00');
        $outstanding = $validBills->reduce(fn ($sum, Bill $b) => bcadd($sum, number_format(max(0, (float) $b->balance), 2, '.', ''), 2), '0.00');
        $paymentStatus = match (true) {
            $validBills->isEmpty() || bccomp($payable, $outstanding, 2) === 0 => 'unpaid',
            bccomp($outstanding, '0.00', 2) <= 0 => 'fully_paid',
            default => 'partially_paid',
        };

        $variance = $validBills->reduce(fn ($sum, Bill $b) => bcadd($sum, $b->priceVariance(), 2), '0.00');
        $uninvoiced = bcsub($acceptedValue, $covered, 2);
        $exceptions = [];
        if (bccomp($variance, '0.00', 2) !== 0) {
            $exceptions[] = ['key' => 'price_variance', 'amount' => $variance,
                'message' => 'Supplier invoices differ from the value received by '.number_format((float) $variance, 2).'.'];
        }
        if ($validBills->isNotEmpty() && bccomp($uninvoiced, '0.00', 2) > 0) {
            $exceptions[] = ['key' => 'received_not_invoiced', 'amount' => $uninvoiced,
                'message' => number_format((float) $uninvoiced, 2).' of received goods is not yet covered by a supplier invoice.'];
        }

        $credited = $validBills->reduce(fn ($sum, Bill $b) => bcadd($sum, number_format((float) ($b->credited_amount ?? 0), 2, '.', ''), 2), '0.00');
        $paid = $validBills->reduce(fn ($sum, Bill $b) => bcadd($sum, number_format((float) $b->paid_amount, 2, '.', ''), 2), '0.00');
        if ($paymentStatus === 'fully_paid' && bccomp($paid, '0.00', 2) <= 0 && bccomp($credited, '0.00', 2) > 0) {
            // Cleared by the supplier's credit, not by money: not "paid".
            $paymentStatus = 'credited';
        }
        $pendingVariance = $validBills->filter(fn (Bill $b) => $b->verified_at === null && bccomp($b->priceVariance(), '0.00', 2) !== 0)->count();
        if ($pendingVariance > 0) {
            $exceptions[] = ['key' => 'variance_pending', 'amount' => null,
                'message' => $pendingVariance.' invoice(s) with a price difference are waiting to be verified.'];
        }

        return [
            'credited_value' => $credited,
            'reversed_bills' => $order->bills->whereNotNull('reversed_at')->count(),
            'variance_pending' => $pendingVariance > 0,
            'receipt_status' => $receiptStatus,
            'billing_status' => $billingStatus,
            'payment_status' => $paymentStatus,
            'received_value' => $acceptedValue,
            'billed_receipt_value' => $covered,
            'received_not_invoiced' => bccomp($uninvoiced, '0.00', 2) > 0 ? $uninvoiced : '0.00',
            'price_variance' => $variance,
            'has_exception' => $exceptions !== [],
            'exceptions' => $exceptions,
        ];
    }

    /** Accrued receipt value on an order that no other live invoice has taken. */
    private function uninvoicedAccrual(PurchaseOrder $order, int $excludingBillId): string
    {
        $matching = app(BillMatchingService::class);
        $total = '0.00';
        foreach ($order->items as $item) {
            foreach ($item->goodsReceiptNoteItems as $receipt) {
                $total = bcadd($total, $matching->outstanding($receipt, $excludingBillId)['value'], 2);
            }
        }

        return $total;
    }

    /** Who resolves a failed check and how (Report 76A-3 §7). */
    private const CHECK_GUIDE = [
        'order' => ['owner' => 'Procurement', 'action' => 'Get the purchase order approved.'],
        'supplier' => ['owner' => 'Accounts', 'action' => 'Record the invoice against the order of the supplier who issued it.'],
        'reference' => ['owner' => 'Accounts', 'action' => 'Enter the supplier\'s invoice number.'],
        'receipt' => ['owner' => 'Stores', 'action' => 'Receive and confirm the goods on a goods receipt note.'],
        'accepted_value' => ['owner' => 'Stores', 'action' => 'Receive the remaining goods, or have the supplier correct the invoice.'],
        'order_total' => ['owner' => 'Procurement', 'action' => 'Amend the order, or have the supplier correct the invoice.'],
        'line_match' => ['owner' => 'Stores', 'action' => 'Receive the goods the unmatched line is for, or correct its quantity.'],
        'price_variance' => ['owner' => 'Finance', 'action' => 'Explain the difference on the line; if an account is required, approve one in Finance Setup > Accounting Policies.'],
        'receipt_accrued' => ['owner' => 'Finance', 'action' => 'Post the receipt: Finance > Setup > System checks > Cost postings lists it with a Retry.'],
    ];

    /**
     * A bill's position as four separate answers (Report 76A-3 §7). None
     * implies another: verified is not paid, credited is not paid, and an
     * invoice that reads verified with no entry behind it says so.
     */
    private function billStatuses(Bill $bill, bool $verified, bool $returned, bool $settled, bool $hasVariance, $checks): array
    {
        $posted = \App\Modules\Finance\Models\JournalEntry::where('entry_no', 'JE-BILL-'.str_pad((string) $bill->id, 7, '0', STR_PAD_LEFT))
            ->where('status', 'posted')->exists();
        $paid = bccomp((string) $bill->paid_amount, '0.00', 2) > 0;
        $credited = bccomp((string) ($bill->credited_amount ?? 0), '0.00', 2) > 0;

        return [
            'document' => match (true) {
                $bill->reversed_at !== null => 'reversed',
                $bill->status === 'cancelled' => 'cancelled',
                $verified => 'approved',
                $returned => 'returned',
                default => 'submitted',
            },
            'matching' => match (true) {
                $bill->isDirect() => 'not_applicable',
                $checks->where('key', 'line_match')->where('passed', false)->isNotEmpty(),
                $checks->whereIn('key', ['receipt', 'accepted_value'])->where('passed', false)->isNotEmpty() => 'unmatched',
                $checks->where('key', 'price_variance')->where('passed', false)->isNotEmpty() => 'variance_pending',
                $hasVariance => 'matched_with_variance',
                default => 'matched',
            },
            'payment' => match (true) {
                $bill->reversed_at !== null => 'reversed',
                $settled && ! $paid && $credited => 'credited',
                $settled => 'paid',
                $paid => 'partially_paid',
                default => 'unpaid',
            },
            'posting' => match (true) {
                $posted => 'posting_complete',
                $bill->verified_at !== null && $bill->verification_basis !== 'legacy' => 'posting_failed',
                $bill->verification_basis === 'legacy' => 'not_applicable',
                default => 'posting_pending',
            },
        ];
    }

    /**
     * The three-way match for one supplier invoice.
     *
     * A part delivery may be part invoiced, so "everything ordered has arrived"
     * is a stage signal, not a payment blocker. What must hold before money
     * leaves is narrower and harder: the invoice may not exceed the value of
     * what Stores actually accepted, nor the order Accounts actually approved.
     */
    public function bill(Bill $bill): array
    {
        $bill->loadMissing(['purchaseOrder', 'verifiedBy']);
        $order = $bill->purchaseOrder;

        if ($bill->isDirect()) {
            return $this->directBill($bill);
        }

        if (! $order) {
            // Defensive only: purchase_order_id is nullable exclusively for
            // direct bills (isDirect() above), and the column's foreign key
            // prevents a purchase order from being deleted while a bill
            // still references it — so reaching here means the id itself is
            // corrupt data, not a legitimate direct bill.
            return [
                'stage' => 'invoice',
                'stage_index' => array_search('invoice', self::STAGES, true),
                'stages' => self::STAGES,
                'owner' => 'Accounts',
                'next_action' => 'This invoice\'s purchase order reference is invalid. It cannot be verified.',
                'checks' => [],
                'eligible_for_verification' => false,
                'verified' => false,
                'can_pay' => false,
                'blockers' => ['The purchase order this invoice referenced could not be found.'],
                'fingerprint' => null,
            ];
        }

        $workflow = $this->order($order);

        /*
         * The match is made on the NET invoice value, because that is the only
         * figure comparable to what it is checked against: an order is priced
         * VAT-exclusive and Stores accepts quantities at those prices. Comparing
         * the gross would fail every VAT-bearing invoice by exactly the tax, and
         * "the invoice exceeds what was accepted" would be reported for an
         * invoice that agreed with the order precisely.
         *
         * Invoices predating tax capture carry net = amount, so this is the
         * same comparison it always was for them.
         */
        // Report 76A Part 2 (Decision A): what is matched is the value of
        // RECEIVED goods this invoice covers, at the order's prices. For an
        // invoice that states no price difference that is its own net value,
        // exactly as before. Where the supplier charged more or less, the
        // difference is a purchase-price variance, checked separately below —
        // it is neither extra quantity billed nor a reason to fail the match.
        $amount = $bill->receiptValueCovered();
        $variance = $bill->priceVariance();
        $hasVariance = bccomp($variance, '0.00', 2) !== 0;
        $varianceAccount = \App\Modules\Finance\Support\GovernedPostingAccount::code(\App\Modules\Finance\Support\GovernedPostingAccount::PURCHASE_PRICE_VARIANCE);
        // A difference the preparer stated needs their reason. One that arises
        // only because the invoice's VAT cannot be recovered explains itself.
        $varianceExplained = filled($bill->price_variance_reason) || $bill->receipt_value_covered === null;
        // W2-3: excludes this bill itself — re-verifying an existing bill
        // must not count its own amount against its own remaining balance.
        // For a persisted bill this is a real exclusion; for one not yet
        // saved (id is null), excluding null is a no-op, which is correct —
        // it is not yet among "other" bills either way.
        $remainingBillable = $order->remainingBillable(excludingBillId: $bill->id);

        // Closure-gate §6: while only one Bill could ever exist per order,
        // "this invoice's amount ≤ total accepted value" WAS the cumulative
        // check — there was only one invoice to compare. W2-3 allowed
        // several, and without this exclusion three staged Bills for 20/20/1
        // units against a 40-unit receipt would each individually still
        // pass (20k, 20k and 1k are each ≤ 40k accepted), jointly billing
        // more than Stores ever accepted. Mirrors remainingBillable's own
        // exclusion above, applied to the receipt basis instead of the PO's
        // approved value.
        $remainingAccepted = bcsub(
            $workflow['accepted_value'], $order->totalBilled(excludingBillId: $bill->id), 2
        );
        $remainingAccepted = bccomp($remainingAccepted, '0.00', 2) > 0 ? $remainingAccepted : '0.00';

        $checks = [
            [
                'key' => 'order',
                'label' => 'Purchase order approved',
                'detail' => 'Order '.$order->po_number,
                'passed' => $workflow['order_approved'],
            ],
            [
                'key' => 'supplier',
                'label' => 'Invoice supplier matches the order supplier',
                'detail' => $workflow['supplier_name'] ?? 'Supplier',
                'passed' => (int) $bill->supplier_id === (int) $order->supplier_id,
            ],
            [
                'key' => 'reference',
                'label' => "Supplier's own invoice number recorded",
                'detail' => trim((string) $bill->supplier_invoice_number) ?: 'Not recorded',
                'passed' => trim((string) $bill->supplier_invoice_number) !== '',
            ],
            [
                'key' => 'receipt',
                'label' => 'Goods accepted into stock by Stores',
                'detail' => $workflow['receipt_complete']
                    ? 'All ordered quantities accepted'
                    : 'Accepted so far: '.number_format((float) $workflow['accepted_value'], 2),
                'passed' => bccomp($workflow['accepted_value'], '0.00', 2) > 0,
            ],
            [
                'key' => 'accepted_value',
                // W2-3: measured against what is still unbilled of the
                // accepted receipt — Accepted Value minus every OTHER valid
                // bill already raised against it — not the full accepted
                // value, now that more than one bill may exist. Unchanged
                // for any order with only this one bill.
                'label' => 'Invoice does not exceed the remaining accepted (unbilled) value',
                'detail' => number_format((float) $amount, 2).' invoiced against '
                    .number_format((float) $remainingAccepted, 2).' remaining accepted',
                'passed' => bccomp($amount, $remainingAccepted, 2) <= 0,
            ],
            [
                'key' => 'order_total',
                // W2-3: measured against what is still billable on this order
                // — Approved Value minus every OTHER valid bill already
                // raised against it — not the order's full value, now that
                // more than one bill may exist. For an order with only this
                // one bill, remaining billable equals the order total, so
                // this is unchanged for every pre-existing single-bill order.
                'label' => 'Invoice does not exceed the order\'s remaining billable amount',
                'detail' => number_format((float) $amount, 2).' invoiced against '
                    .number_format((float) $remainingBillable, 2).' remaining billable',
                'passed' => bccomp($amount, $remainingBillable, 2) <= 0,
            ],
        ];

        /*
         * Report 76A-3 — the line-level match.
         *
         * An invoice with lines is matched line by line to accepted receipts;
         * the checks above stay as the order-level backstop. What the lines add:
         * every billed quantity names the receipt it is for, every price
         * difference is explained, and whatever part of a difference cannot go
         * to stock still on hand has an approved account to go to.
         */
        $bill->loadMissing(['lines.allocations.goodsReceiptNoteItem', 'lines.purchaseOrderItem.material', 'vatTreatment']);
        $toVariance = $variance;
        if ($bill->lines->isNotEmpty()) {
            $unmatched = $bill->lines->reject(fn ($line) => $line->isFullyAllocated());
            $checks[] = [
                'key' => 'line_match',
                'label' => 'Every invoice line is matched to accepted receipts',
                'detail' => $unmatched->isEmpty()
                    ? $bill->lines->count().' line(s) matched to the receipts they cover.'
                    : 'Line '.$unmatched->pluck('line_no')->implode(', ').' bills more than has been accepted and not yet invoiced. '
                        .'Receive the goods, or correct the quantity.',
                'passed' => $unmatched->isEmpty(),
            ];
            $unexplained = app(BillMatchingService::class)->unexplainedLines($bill);
            $varianceExplained = $unexplained->isEmpty();
            $toVariance = collect(app(BillCostDifferenceService::class)->plan($bill))
                ->reduce(fn ($sum, $part) => bcadd($sum, $part['to_variance'], 2), '0.00');
        }
        $needsVarianceAccount = bccomp($toVariance, '0.00', 2) !== 0;

        /*
         * An invoice clears the accrual its receipts raised — so the receipts
         * must have raised one. A receipt whose posting failed, or that
         * predates receipt accruals altogether, has put nothing in the ledger;
         * clearing it anyway would relieve a liability that was never recorded
         * and drive the accrual account the wrong way. Asked only of an
         * invoice not yet verified: one already posted is read as it stands.
         */
        if ($bill->verified_at === null && bccomp($workflow['accepted_value'], '0.00', 2) > 0) {
            $uninvoiced = $this->uninvoicedAccrual($order, (int) $bill->id);
            $needed = $bill->lines->isNotEmpty()
                ? number_format((float) $bill->lines->sum('receipt_value_covered'), 2, '.', '')
                : $bill->netAmount();
            $claims = $bill->lines->isNotEmpty() ? '0.00' : $needed;   // a line-matched bill already holds its allocations
            $checks[] = [
                'key' => 'receipt_accrued',
                'label' => 'The accepted receipts have reached the ledger',
                'detail' => bccomp($claims, $uninvoiced, 2) <= 0
                    ? 'The receipts this invoice clears are accrued in the ledger.'
                    : 'Only '.number_format((float) $uninvoiced, 2).' of accepted receipts on this order is accrued in the ledger and not yet invoiced, against '
                        .number_format((float) $needed, 2).' on this invoice. A receipt whose posting failed, or that predates receipt accruals, must be posted first.',
                'passed' => bccomp($claims, $uninvoiced, 2) <= 0,
            ];
        }

        if ($hasVariance) {
            $checks[] = [
                'key' => 'price_variance',
                'label' => match (true) {
                    ! $varianceExplained => 'Price difference from the receipt value is explained',
                    $needsVarianceAccount && $varianceAccount === null => 'PURCHASE PRICE VARIANCE ACCOUNT REQUIRED',
                    default => 'Cost difference from the receipt value is explained and has somewhere to go',
                },
                'detail' => 'Invoice cost '.number_format((float) $bill->invoiceCostBasis(), 2).' against received value '
                    .number_format((float) $amount, 2).': a difference of '.number_format((float) $variance, 2).'. '
                    .($needsVarianceAccount && $varianceAccount === null
                        ? 'Accounting configuration required — '.number_format((float) $toVariance, 2).' of it cannot be put to stock still on hand, and Finance has not approved an account for purchase price variance (Finance Setup > Accounting Policies). It is not posted to stock, the job or the supplier balance instead.'
                        : ($bill->price_variance_reason ?: 'The invoice carries VAT that cannot be recovered.')
                            .($bill->lines->isNotEmpty() ? ' Stock on hand takes '.number_format((float) bcsub($variance, $toVariance, 2), 2).'; the variance account takes '.number_format((float) $toVariance, 2).'.' : '')),
                'passed' => $varianceExplained && (! $needsVarianceAccount || $varianceAccount !== null),
            ];
        }

        /*
         * Input VAT and the tax document behind it.
         *
         * Whether a supply's VAT may be claimed without an eTIMS invoice is a
         * property of its VAT treatment (`requires_etims`), and WNG's existing
         * rule is that such an invoice is still verified and posted, then held
         * out of the VAT return as "at risk" until the document is supplied —
         * the claim gate lives on the return. That rule is kept: this does not
         * block verification. It states the position on the invoice itself so
         * the person verifying sees it before, not after, the return is drawn.
         */
        $claimsVat = $bill->vatTreatment?->is_recoverable && (float) ($bill->vat_amount ?? 0) > 0;
        $taxDocument = [
            'claims_input_vat' => (bool) $claimsVat,
            'requires_etims' => (bool) ($claimsVat && $bill->vatTreatment->requires_etims),
            'supported' => ! ($claimsVat && $bill->vatTreatment->requires_etims) || filled($bill->etims_invoice_no),
        ];
        $taxDocument['message'] = $taxDocument['supported'] ? null
            : 'Input VAT of '.number_format((float) $bill->vat_amount, 2).' has no eTIMS invoice number behind it. It is posted, but held out of the VAT return as at risk until the number is added.';

        // Report 76A-3 §7: who acts on a failed check, and what they do.
        $checks = array_map(fn (array $check) => $check + (self::CHECK_GUIDE[$check['key']] ?? ['owner' => 'Accounts', 'action' => null]), $checks);

        // Report 60 §16: a bill returned to its preparer cannot be verified
        // until they correct and resubmit it, whatever the checks say.
        $returned = $bill->awaitingCorrection();
        $eligible = collect($checks)->every(fn ($check) => $check['passed']) && ! $returned;
        $fingerprint = $this->fingerprint($bill, $workflow);

        /*
         * Invoices raised before the match existed carry a 'legacy' basis. They
         * stay payable so open supplier balances could still be settled at the
         * changeover, and they say so on every screen rather than passing
         * themselves off as matched.
         */
        $legacy = $bill->verification_basis === 'legacy' && $bill->verified_at !== null;
        $matched = $eligible
            && $bill->verified_at !== null
            && hash_equals((string) $bill->verification_fingerprint, $fingerprint);
        $verified = $matched || $legacy;

        $blockers = $legacy ? [] : collect($checks)->where('passed', false)->pluck('label')->values()->all();
        if (! $verified) {
            $blockers[] = match (true) {
                $returned => 'Returned to its preparer for correction: '.$bill->return_reason,
                ! $eligible => 'Resolve the checks above, then verify the invoice.',
                $bill->verified_at === null => 'Accounts must verify this invoice before it can be paid.',
                default => 'The order, receipt or invoice changed after verification. Verify it again.',
            };
        }

        $settled = bccomp((string) $bill->balance, '0.00', 2) <= 0;
        $stage = match (true) {
            $settled => 'complete',
            $verified => 'payment',
            $workflow['stage'] === 'invoice' => 'verification',
            default => $workflow['stage'],
        };

        return [
            ...$workflow,
            'stage' => $stage,
            'stage_index' => array_search($stage, self::STAGES, true),
            'owner' => self::STAGE_GUIDE[$stage][0],
            'next_action' => self::STAGE_GUIDE[$stage][1],
            'bill_id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'bill_amount' => $amount,
            'bill_net' => $bill->netAmount(),
            'receipt_value_covered' => $amount,
            'price_variance' => $variance,
            'price_variance_reason' => $bill->price_variance_reason,
            'price_variance_account_ready' => $varianceAccount !== null,
            'statuses' => $this->billStatuses($bill, $verified, $returned, $settled, $hasVariance, collect($checks)),
            'credited_amount' => (string) ($bill->credited_amount ?? 0),
            'reversed_at' => $bill->reversed_at?->toDateTimeString(),
            'reversal_reason' => $bill->reversal_reason,
            'has_lines' => $bill->lines->isNotEmpty(),
            'tax_document' => $taxDocument,
            'bill_gross' => (string) $bill->amount,
            'bill_vat' => (string) ($bill->vat_amount ?? 0),
            'bill_wht' => (string) ($bill->wht_amount ?? 0),
            'bill_payable' => $bill->payableAmount(),
            // W2-3: the staged-billing position for THIS invoice, net of tax
            // throughout (the basis the cap is enforced on). The screen shows
            // these figures; it never recomputes them.
            'billing_position' => [
                'order_value' => (string) $order->total_amount,
                'previously_billed' => $order->totalBilled(excludingBillId: $bill->id),
                'this_bill' => (string) $amount,
                'total_billed' => bcadd($order->totalBilled(excludingBillId: $bill->id), (string) $amount, 2),
                'remaining_billable' => $order->remainingBillable(),
            ],
            'bill_balance' => (string) $bill->balance,
            'supplier_invoice_number' => $bill->supplier_invoice_number,
            'checks' => $checks,
            'eligible_for_verification' => $eligible,
            'verified' => $verified,
            'verification_basis' => $legacy ? 'legacy' : ($matched ? 'three_way_match' : null),
            'verified_at' => $bill->verified_at?->toDateTimeString(),
            'verified_by' => $bill->verifiedBy?->name,
            'verification_notes' => $bill->verification_notes,
            'can_pay' => $verified && $bill->status !== 'cancelled' && ! $settled,
            'blockers' => $blockers,
            'fingerprint' => $fingerprint,
            'awaiting_correction' => $returned,
        ];
    }

    /**
     * Everything a verification was a statement about. Change any of it and the
     * stored fingerprint stops matching, which withdraws the verification
     * rather than letting a stale sign-off carry a re-priced invoice to payment.
     */
    public function fingerprint(Bill $bill, ?array $workflow = null): string
    {
        $workflow ??= $this->order($bill->purchaseOrder);

        return hash('sha256', json_encode([
            'order' => [$workflow['order_id'], $workflow['order_approved'], $workflow['supplier_id'], $workflow['order_total']],
            'accepted' => $workflow['accepted_value'],
            'items' => array_map(fn ($item) => [$item['id'], $item['ordered'], $item['accepted'], $item['unit_price']], $workflow['items']),
            // The tax split is part of what was verified: change the VAT or the
            // withholding after sign-off and the amount actually leaving changes
            // with it, so the verification must withdraw exactly as it does for
            // a re-priced line.
            'invoice' => [
                (int) $bill->supplier_id,
                (string) $bill->supplier_invoice_number,
                (string) $bill->amount,
                (string) ($bill->net_amount ?? $bill->amount),
                (string) ($bill->vat_amount ?? 0),
                (string) ($bill->wht_amount ?? 0),
                // Only when stated, so every invoice verified before a receipt
                // value could be stated keeps the fingerprint it was signed with.
                ...($bill->receipt_value_covered !== null ? [(string) $bill->receipt_value_covered] : []),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A direct bill has no order, receipt or three-way match to check against
     * — it names a supplier, an expense classification, and its own invoice
     * number, and Accounts verifies that as a genuine, correctly classified
     * obligation rather than reconciling three documents.
     */
    private function directBill(Bill $bill): array
    {
        $checks = [
            [
                'key' => 'supplier',
                'label' => 'Supplier registered in the Supplier Master',
                'detail' => $bill->supplier?->supplier_name ?? 'Supplier',
                'passed' => $bill->supplier_id !== null,
            ],
            [
                'key' => 'expense_code',
                'label' => 'Expense classification recorded',
                'detail' => $bill->expenseCode?->simple_meaning ?? 'Not set',
                'passed' => $bill->expense_code_id !== null,
            ],
            [
                'key' => 'reference',
                'label' => "Supplier's own invoice number recorded",
                'detail' => trim((string) $bill->supplier_invoice_number) ?: 'Not recorded',
                'passed' => trim((string) $bill->supplier_invoice_number) !== '',
            ],
        ];

        $returned = $bill->awaitingCorrection();
        $eligible = collect($checks)->every(fn ($check) => $check['passed']) && ! $returned;
        $fingerprint = $this->directFingerprint($bill);
        $matched = $eligible
            && $bill->verified_at !== null
            && hash_equals((string) $bill->verification_fingerprint, $fingerprint);

        $blockers = collect($checks)->where('passed', false)->pluck('label')->values()->all();
        if (! $matched) {
            $blockers[] = match (true) {
                $returned => 'Returned to its preparer for correction: '.$bill->return_reason,
                ! $eligible => 'Resolve the checks above, then verify the invoice.',
                $bill->verified_at === null => 'Accounts must verify this invoice before it can be paid.',
                default => 'The invoice changed after verification. Verify it again.',
            };
        }

        $settled = bccomp((string) $bill->balance, '0.00', 2) <= 0;
        $stage = match (true) {
            $settled => 'complete',
            $matched => 'payment',
            default => 'verification',
        };
        $nextAction = match ($stage) {
            'verification' => 'Check the supplier and expense classification, then verify this direct invoice.',
            'payment' => 'The invoice is verified. Record the supplier payment.',
            default => self::STAGE_GUIDE['complete'][1],
        };

        // A direct bill never had an order, delivery or stores stage to pass
        // through — showing it against the full 7-stage PO journey (the same
        // `self::STAGES`/`PurchaseTrail` component every order screen shares)
        // would tick off three stages that never happened.
        $directStages = ['verification', 'payment', 'complete'];

        return [
            'stage' => $stage,
            'stage_index' => array_search($stage, $directStages, true),
            'stages' => $directStages,
            'order_number' => $bill->bill_number,
            'owner' => 'Accounts',
            'next_action' => $nextAction,
            'bill_id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'bill_amount' => $bill->netAmount(),
            'bill_gross' => (string) $bill->amount,
            'bill_vat' => (string) ($bill->vat_amount ?? 0),
            'bill_wht' => (string) ($bill->wht_amount ?? 0),
            'bill_payable' => $bill->payableAmount(),
            'bill_balance' => (string) $bill->balance,
            'supplier_invoice_number' => $bill->supplier_invoice_number,
            'checks' => $checks,
            'eligible_for_verification' => $eligible,
            'verified' => $matched,
            'verification_basis' => $matched ? 'direct' : null,
            'verified_at' => $bill->verified_at?->toDateTimeString(),
            'verified_by' => $bill->verifiedBy?->name,
            'verification_notes' => $bill->verification_notes,
            'can_pay' => $matched && $bill->status !== 'cancelled' && ! $settled,
            'blockers' => $blockers,
            'fingerprint' => $fingerprint,
            'is_direct' => true,
            'awaiting_correction' => $returned,
        ];
    }

    /** The direct-bill analogue of fingerprint() — no order or receipt to fold in. */
    private function directFingerprint(Bill $bill): string
    {
        return hash('sha256', json_encode([
            'invoice' => [
                (int) $bill->supplier_id,
                (int) $bill->expense_code_id,
                (string) $bill->supplier_invoice_number,
                (string) $bill->amount,
                (string) ($bill->net_amount ?? $bill->amount),
                (string) ($bill->vat_amount ?? 0),
                (string) ($bill->wht_amount ?? 0),
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
