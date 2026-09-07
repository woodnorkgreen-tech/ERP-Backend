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
        if (! $receiptItem->accepted || $receiptItem->store_status !== 'confirmed') {
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

        $stage = match (true) {
            ! $approved => 'approval',
            $order->goodsReceiptNotes->isEmpty() => 'delivery',
            ! $anyAccepted || $awaitingStores => 'stores',
            ! $bill => 'invoice',
            default => 'verification',
        };

        if ($bill && $stage === 'verification') {
            if (bccomp((string) $bill->balance, '0.00', 2) <= 0) {
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

        if (! $order) {
            return [
                'stage' => 'invoice',
                'stage_index' => array_search('invoice', self::STAGES, true),
                'stages' => self::STAGES,
                'owner' => 'Accounts',
                'next_action' => 'This invoice has no purchase order. Link it to one before paying.',
                'checks' => [],
                'eligible_for_verification' => false,
                'verified' => false,
                'can_pay' => false,
                'blockers' => ['This invoice is not linked to a purchase order.'],
                'fingerprint' => null,
            ];
        }

        $workflow = $this->order($order);
        $amount = (string) $bill->amount;

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
                'label' => 'Invoice does not exceed the value accepted into stock',
                'detail' => number_format((float) $amount, 2).' invoiced against '
                    .number_format((float) $workflow['accepted_value'], 2).' accepted',
                'passed' => bccomp($amount, $workflow['accepted_value'], 2) <= 0,
            ],
            [
                'key' => 'order_total',
                'label' => 'Invoice does not exceed the approved order',
                'detail' => number_format((float) $amount, 2).' invoiced against '
                    .number_format((float) $workflow['order_total'], 2).' approved',
                'passed' => bccomp($amount, $workflow['order_total'], 2) <= 0,
            ],
        ];

        $eligible = collect($checks)->every(fn ($check) => $check['passed']);
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
            'invoice' => [(int) $bill->supplier_id, (string) $bill->supplier_invoice_number, (string) $bill->amount],
        ], JSON_THROW_ON_ERROR));
    }
}
