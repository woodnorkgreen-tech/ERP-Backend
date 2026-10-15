<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\BillLine;
use App\Modules\ProcurementStores\Models\BillLineReceiptAllocation;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\ReceiptAccrualCloseout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Supplier bill lines and the accepted receipts they cover (Report 76A-3).
 *
 * A bill used to be one amount. The value of received goods it cleared was
 * whatever its net said, or — after Report 76A Part 2 — a figure its preparer
 * typed. Neither could be checked: the system did not know which delivery, or
 * how much of it, an invoice was for.
 *
 * Here a bill has lines. Each line names an order line, a quantity and a net
 * unit price, and is ALLOCATED to specific accepted goods-receipt quantities.
 * The receipt value a line covers is the accrued value of those quantities —
 * read from the receipt's own posted accrual, so the two always reconcile —
 * and is never accepted from a request.
 *
 *   receipt value covered = Σ allocations (accrued value of the quantity taken)
 *   cost difference       = line net + VAT that cannot be recovered − receipt value covered
 *
 * Guards, all under a row lock on the receipt lines being billed:
 *
 *   - a receipt quantity is billed once: allocations across every live bill,
 *     less credits, plus any close-out, may not exceed what was accepted;
 *   - a line may not cover a receipt line of another order line;
 *   - one line covers one receipt line once (unique key);
 *   - lines must add up to the invoice's own net, to the cent.
 *
 * A bill posted without lines (an older API caller, an import) is a SUMMARY
 * bill: its lines are derived at verification by allocating its net at order
 * prices, oldest receipt first. By construction it can state no price
 * difference; one that needs to, needs lines.
 */
class BillMatchingService
{
    /* ── receipt side ───────────────────────────────────────────────────── */

    /**
     * What a receipt line accrued: the quantity and value its posted accrual
     * (or, for a non-stock purchase, its posted actual) carries.
     *
     * @return array{quantity: string, value: string}|null  null when nothing is posted for it
     */
    public function accrual(GoodsReceiptNoteItem $item): ?array
    {
        if ($item->reversed_at || ! $item->accepted) {
            return null;
        }
        $line = CostLine::where('source_type', GoodsReceiptNoteItem::class)->where('source_id', $item->id)
            ->whereIn('source_ref', CostLine::RECEIPT_LIABILITY_REFS)
            ->whereHas('journalEntry', fn ($journal) => $journal->where('status', 'posted'))
            ->first();
        if (! $line) {
            return null;
        }
        $quantity = $this->quantity($line->details['quantity'] ?? $item->received_quantity);
        if (bccomp($quantity, '0', 6) <= 0) {
            return null;
        }

        return ['quantity' => $quantity, 'value' => $this->money($line->net_amount)];
    }

    /**
     * What live bills, credits and a close-out have already taken of a receipt line.
     *
     * $lock makes this a LOCKING read, and inside an allocation it must be one.
     * Holding the receipt row is not enough on its own: under repeatable read a
     * plain SELECT answers from the snapshot the transaction took before it
     * waited for that row, so a second invoice would see nothing billed by the
     * first and bill the same goods again. (The forked race test did exactly
     * that, four times over, before this was a locking read.) A locking read
     * returns what is committed now.
     *
     * @return array{quantity: string, value: string}
     */
    public function taken(GoodsReceiptNoteItem $item, ?int $excludingBillId = null, bool $lock = false): array
    {
        $rows = DB::table('bill_line_receipt_allocations as a')
            ->join('bill_lines as l', 'l.id', '=', 'a.bill_line_id')
            ->join('bills as b', 'b.id', '=', 'l.bill_id')
            ->where('a.goods_receipt_note_item_id', $item->id)
            ->where('b.status', '!=', 'cancelled')->whereNull('b.reversed_at')
            ->when($excludingBillId, fn ($query) => $query->where('b.id', '!=', $excludingBillId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['a.quantity', 'a.released_quantity', 'a.receipt_value', 'a.released_value']);
        $closed = ReceiptAccrualCloseout::where('goods_receipt_note_item_id', $item->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        $quantity = $this->quantity($closed?->quantity ?? 0);
        $value = $this->money($closed?->value ?? 0);
        foreach ($rows as $row) {
            $quantity = bcadd($quantity, bcsub($this->quantity($row->quantity), $this->quantity($row->released_quantity), 6), 6);
            $value = bcadd($value, bcsub($this->money($row->receipt_value), $this->money($row->released_value), 2), 2);
        }

        return ['quantity' => $quantity, 'value' => $value];
    }

    /**
     * Accepted, accrued and not yet invoiced.
     *
     * @return array{quantity: string, value: string}
     */
    public function outstanding(GoodsReceiptNoteItem $item, ?int $excludingBillId = null, bool $lock = false): array
    {
        $accrual = $this->accrual($item);
        if (! $accrual) {
            return ['quantity' => '0.000000', 'value' => '0.00'];
        }
        $taken = $this->taken($item, $excludingBillId, $lock);
        $quantity = bcsub($accrual['quantity'], $taken['quantity'], 6);
        $value = bcsub($accrual['value'], $taken['value'], 2);

        return [
            'quantity' => bccomp($quantity, '0', 6) > 0 ? $quantity : '0.000000',
            'value' => bccomp($quantity, '0', 6) > 0 && bccomp($value, '0', 2) > 0 ? $value : '0.00',
        ];
    }

    /**
     * The accrued value of taking $quantity more of a receipt line. Taking the
     * last of it takes whatever value is left, so cents never strand.
     */
    private function valueOf(GoodsReceiptNoteItem $item, string $quantity, ?int $excludingBillId): string
    {
        $accrual = $this->accrual($item);
        $outstanding = $this->outstanding($item, $excludingBillId, lock: true);
        if (bccomp($quantity, $outstanding['quantity'], 6) >= 0) {
            return $outstanding['value'];
        }

        return $this->round(bcdiv(bcmul($quantity, $accrual['value'], 10), $accrual['quantity'], 10));
    }

    /** The receipt lines of one order line that can still be billed, oldest first, locked. */
    private function billableReceipts(int $purchaseOrderItemId): Collection
    {
        return GoodsReceiptNoteItem::where('purchase_order_item_id', $purchaseOrderItemId)
            ->where('accepted', true)->whereNull('reversed_at')
            ->orderBy('id')->lockForUpdate()->get();
    }

    /* ── stated lines ───────────────────────────────────────────────────── */

    /**
     * Replace an unverified bill's lines with the ones its preparer stated.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    public function syncStated(Bill $bill, array $lines): void
    {
        if ($bill->isDirect()) {
            return;
        }
        if ($bill->verified_at) {
            $this->refuse('lines', 'A verified invoice\'s lines cannot be changed. Credit or reverse it instead.');
        }

        DB::transaction(function () use ($bill, $lines) {
            $bill->lines()->each(fn (BillLine $line) => $line->delete());
            $orderItems = PurchaseOrderItem::where('purchase_order_id', $bill->purchase_order_id)->get()->keyBy('id');
            $billNet = $this->money($bill->net_amount);
            $seen = [];
            $built = [];
            $total = '0.00';

            foreach (array_values($lines) as $index => $input) {
                $at = "lines.{$index}";
                $item = $orderItems->get((int) ($input['purchase_order_item_id'] ?? 0));
                if (! $item) {
                    $this->refuse("{$at}.purchase_order_item_id", 'This line is not a line of the order the invoice is against.');
                }
                if (isset($seen[$item->id])) {
                    $this->refuse("{$at}.purchase_order_item_id", 'An order line appears twice on this invoice. Put its whole quantity on one line.');
                }
                $seen[$item->id] = true;

                $quantity = $this->quantity($input['quantity'] ?? 0);
                $price = number_format((float) ($input['unit_price_net'] ?? 0), 4, '.', '');
                $discount = $this->money($input['discount_amount'] ?? 0);
                if (bccomp($quantity, '0', 6) <= 0) {
                    $this->refuse("{$at}.quantity", 'Enter the quantity invoiced on this line.');
                }
                if (bccomp($price, '0', 4) < 0 || bccomp($discount, '0', 2) < 0) {
                    $this->refuse("{$at}.unit_price_net", 'A price or a discount cannot be negative. A reduction after the invoice is a supplier credit.');
                }
                $extended = $this->round(bcmul($quantity, $price, 10));
                if (bccomp($discount, $extended, 2) > 0) {
                    $this->refuse("{$at}.discount_amount", 'The discount is more than the line itself.');
                }
                $net = bcsub($extended, $discount, 2);
                $total = bcadd($total, $net, 2);
                $built[] = compact('item', 'quantity', 'price', 'discount', 'net', 'input', 'at');
            }

            if ($built === []) {
                $this->refuse('lines', 'An invoice against an order needs at least one line.');
            }
            if (bccomp($total, $billNet, 2) !== 0) {
                $this->refuse('lines', 'The lines add up to '.number_format((float) $total, 2).' before VAT, but the invoice is '
                    .number_format((float) $billNet, 2).' before VAT. They must agree to the cent.');
            }

            $vatByLine = $this->apportionVat($bill, $built);

            foreach ($built as $index => $row) {
                $taxable = (bool) ($row['input']['taxable'] ?? true);
                $line = BillLine::create([
                    'bill_id' => $bill->id, 'line_no' => $index + 1, 'purchase_order_item_id' => $row['item']->id,
                    'description' => $row['input']['description'] ?? ($row['item']->custom_description ?: $row['item']->material?->material_name),
                    'quantity' => $row['quantity'], 'unit_price_net' => $row['price'], 'discount_amount' => $row['discount'],
                    'net_amount' => $row['net'], 'vat_treatment_id' => $taxable ? $bill->vat_treatment_id : null,
                    'vat_amount' => $vatByLine[$index], 'gross_amount' => bcadd($row['net'], $vatByLine[$index], 2),
                    'variance_reason' => filled($row['input']['variance_reason'] ?? null) ? trim((string) $row['input']['variance_reason']) : null,
                    'allocation_basis' => BillLine::BASIS_STATED, 'status' => 'open',
                ]);
                $this->allocate($line, $row['input']['allocations'] ?? null, $row['at']);
            }

            $this->recompute($bill);
        });
    }

    /**
     * The invoice's VAT, shared across its taxable lines in proportion to their
     * net, largest remainder first — so line VAT always adds up to the document.
     *
     * Refused when the document VAT does not agree with its own rate: a line
     * level split of a wrong total would only hide the error.
     */
    private function apportionVat(Bill $bill, array $built): array
    {
        $vat = $this->money($bill->vat_amount);
        $shares = array_fill(0, count($built), '0.00');
        $taxable = array_filter($built, fn ($row) => (bool) ($row['input']['taxable'] ?? true));
        $taxableNet = array_reduce($taxable, fn ($sum, $row) => bcadd($sum, $row['net'], 2), '0.00');

        if (bccomp($vat, '0.00', 2) <= 0) {
            return $shares;
        }
        if (bccomp($taxableNet, '0.00', 2) <= 0) {
            $this->refuse('vat_amount', 'The invoice carries VAT but none of its lines is taxable.');
        }
        $rate = (string) ($bill->vatTreatment?->rate_percent ?? '0');
        if (bccomp($rate, '0', 3) > 0) {
            $expected = $this->round(bcdiv(bcmul($taxableNet, $rate, 10), '100', 10));
            // A cent a line is the most honest rounding can differ by.
            $tolerance = bcmul('0.01', (string) max(1, count($taxable)), 2);
            if (bccomp($this->abs(bcsub($vat, $expected, 2)), $tolerance, 2) > 0) {
                $this->refuse('vat_amount', 'VAT of '.number_format((float) $vat, 2).' does not agree with '.rtrim(rtrim($rate, '0'), '.')
                    .'% of the taxable lines ('.number_format((float) $expected, 2).'). Check the invoice total, the VAT, or which lines are taxable.');
            }
        }

        $allocated = '0.00';
        $remainders = [];
        foreach ($taxable as $index => $row) {
            $exact = bcdiv(bcmul($vat, $row['net'], 10), $taxableNet, 10);
            $shares[$index] = bcadd($exact, '0', 2);            // truncated
            $remainders[$index] = bcsub($exact, $shares[$index], 10);
            $allocated = bcadd($allocated, $shares[$index], 2);
        }
        arsort($remainders);
        $cents = (int) bcmul(bcsub($vat, $allocated, 2), '100', 0);
        foreach (array_keys($remainders) as $index) {
            if ($cents <= 0) {
                break;
            }
            $shares[$index] = bcadd($shares[$index], '0.01', 2);
            $cents--;
        }

        return $shares;
    }

    /**
     * Match a line to accepted receipts: the ones it names, or the oldest
     * outstanding. What cannot be matched stays unallocated — the invoice may
     * have arrived before the goods — and the three-way match refuses it until
     * it is.
     */
    private function allocate(BillLine $line, ?array $stated, string $at): void
    {
        $billId = (int) $line->bill_id;
        $receipts = $this->billableReceipts((int) $line->purchase_order_item_id)->keyBy('id');
        $wanted = (string) $line->quantity;

        if ($stated !== null) {
            $sum = '0';
            foreach ($stated as $position => $allocation) {
                $receipt = $receipts->get((int) ($allocation['goods_receipt_note_item_id'] ?? 0));
                $quantity = $this->quantity($allocation['quantity'] ?? 0);
                if (! $receipt) {
                    $this->refuse("{$at}.allocations.{$position}.goods_receipt_note_item_id", 'That receipt line is not an accepted receipt of this order line.');
                }
                if (bccomp($quantity, '0', 6) <= 0) {
                    $this->refuse("{$at}.allocations.{$position}.quantity", 'Enter the quantity this invoice line covers on that receipt.');
                }
                $outstanding = $this->outstanding($receipt, $billId, lock: true);
                if (bccomp($quantity, $outstanding['quantity'], 6) > 0) {
                    $this->refuse("{$at}.allocations.{$position}.quantity", 'Only '.$this->trim($outstanding['quantity'])
                        .' of that receipt is still to be invoiced; '.$this->trim($quantity).' cannot be billed against it.');
                }
                $this->take($line, $receipt, $quantity, $billId, "{$at}.allocations.{$position}");
                $sum = bcadd($sum, $quantity, 6);
            }
            if (bccomp($sum, $wanted, 6) > 0) {
                $this->refuse("{$at}.allocations", 'The receipts named cover more than the quantity on the line.');
            }

            return;
        }

        foreach ($receipts as $receipt) {
            if (bccomp($wanted, '0', 6) <= 0) {
                break;
            }
            $outstanding = $this->outstanding($receipt, $billId, lock: true);
            if (bccomp($outstanding['quantity'], '0', 6) <= 0) {
                continue;
            }
            $quantity = bccomp($wanted, $outstanding['quantity'], 6) > 0 ? $outstanding['quantity'] : $wanted;
            $this->take($line, $receipt, $quantity, $billId, $at);
            $wanted = bcsub($wanted, $quantity, 6);
        }
    }

    private function take(BillLine $line, GoodsReceiptNoteItem $receipt, string $quantity, int $billId, string $at): void
    {
        if (BillLineReceiptAllocation::where('bill_line_id', $line->id)->where('goods_receipt_note_item_id', $receipt->id)->exists()) {
            $this->refuse($at, 'This invoice line is already matched to that receipt. Put the whole quantity in one entry.');
        }
        BillLineReceiptAllocation::create([
            'bill_line_id' => $line->id, 'goods_receipt_note_item_id' => $receipt->id,
            'quantity' => $quantity, 'receipt_value' => $this->valueOf($receipt, $quantity, $billId),
        ]);
    }

    /**
     * Try again to match what could not be matched when the bill was entered —
     * goods may have been received since. Unverified bills only.
     */
    public function refresh(Bill $bill): void
    {
        if ($bill->isDirect() || $bill->verified_at || $bill->reversed_at) {
            return;
        }
        DB::transaction(function () use ($bill) {
            $touched = false;
            foreach ($bill->lines()->where('allocation_basis', BillLine::BASIS_STATED)->get() as $line) {
                $short = bcsub((string) $line->quantity, (string) $line->allocations()->sum('quantity'), 6);
                if (bccomp($short, '0', 6) <= 0) {
                    continue;
                }
                foreach ($this->billableReceipts((int) $line->purchase_order_item_id) as $receipt) {
                    if (bccomp($short, '0', 6) <= 0) {
                        break;
                    }
                    if ($line->allocations()->where('goods_receipt_note_item_id', $receipt->id)->exists()) {
                        continue;
                    }
                    $outstanding = $this->outstanding($receipt, (int) $bill->id, lock: true);
                    if (bccomp($outstanding['quantity'], '0', 6) <= 0) {
                        continue;
                    }
                    $quantity = bccomp($short, $outstanding['quantity'], 6) > 0 ? $outstanding['quantity'] : $short;
                    $this->take($line, $receipt, $quantity, (int) $bill->id, 'lines');
                    $short = bcsub($short, $quantity, 6);
                    $touched = true;
                }
            }
            if ($touched) {
                $this->recompute($bill);
            }
        });
    }

    /* ── summary bills ──────────────────────────────────────────────────── */

    /**
     * Give a bill that was entered as one amount its lines, at verification.
     *
     * Its net is allocated at order prices across the order's outstanding
     * accepted receipts, oldest first. The match has already confirmed the
     * receipts cover it; if they no longer do (another bill got there first),
     * this throws and the verification rolls back.
     *
     * @throws InvalidArgumentException
     */
    public function deriveLines(Bill $bill): void
    {
        if ($bill->isDirect() || $bill->lines()->exists()) {
            return;
        }

        DB::transaction(function () use ($bill) {
            $remaining = $this->money($bill->net_amount);
            $lineNo = 0;
            $derivedLines = [];
            $items = PurchaseOrderItem::where('purchase_order_id', $bill->purchase_order_id)->orderBy('id')->get();

            foreach ($items as $item) {
                if (bccomp($remaining, '0.00', 2) <= 0) {
                    break;
                }
                $takes = [];
                $lineValue = '0.00';
                $lineQuantity = '0';
                foreach ($this->billableReceipts((int) $item->id) as $receipt) {
                    if (bccomp($remaining, '0.00', 2) <= 0) {
                        break;
                    }
                    $outstanding = $this->outstanding($receipt, (int) $bill->id, lock: true);
                    if (bccomp($outstanding['value'], '0.00', 2) <= 0) {
                        continue;
                    }
                    if (bccomp($remaining, $outstanding['value'], 2) >= 0) {
                        $value = $outstanding['value'];
                        $quantity = $outstanding['quantity'];
                    } else {
                        $value = $remaining;
                        $quantity = bcdiv(bcmul($value, $outstanding['quantity'], 10), $outstanding['value'], 6);
                    }
                    $takes[] = [$receipt->id, $quantity, $value];
                    $lineValue = bcadd($lineValue, $value, 2);
                    $lineQuantity = bcadd($lineQuantity, $quantity, 6);
                    $remaining = bcsub($remaining, $value, 2);
                }
                if ($takes === []) {
                    continue;
                }
                $line = BillLine::create([
                    'bill_id' => $bill->id, 'line_no' => ++$lineNo, 'purchase_order_item_id' => $item->id,
                    'description' => $item->custom_description ?: $item->material?->material_name,
                    'quantity' => $lineQuantity, 'unit_price_net' => number_format((float) $item->unit_price, 4, '.', ''),
                    'discount_amount' => '0.00', 'net_amount' => $lineValue, 'vat_treatment_id' => $bill->vat_treatment_id,
                    'vat_amount' => '0.00', 'gross_amount' => $lineValue,
                    'allocation_basis' => BillLine::BASIS_DERIVED, 'status' => 'open',
                ]);
                $derivedLines[] = $line;
                foreach ($takes as [$receiptId, $quantity, $value]) {
                    BillLineReceiptAllocation::create(['bill_line_id' => $line->id, 'goods_receipt_note_item_id' => $receiptId,
                        'quantity' => $quantity, 'receipt_value' => $value]);
                }
            }

            if (bccomp($remaining, '0.00', 2) > 0) {
                throw new InvalidArgumentException('This invoice covers '.number_format((float) $remaining, 2)
                    .' more than the accepted receipts still to be invoiced on its order. Record its lines against the receipts it is for.');
            }

            // The document's VAT, shared across the derived lines by value.
            $shares = $this->apportionVat($bill, array_map(fn (BillLine $line) => ['net' => (string) $line->net_amount, 'input' => []], $derivedLines));
            foreach ($derivedLines as $index => $line) {
                $line->forceFill(['vat_amount' => $shares[$index], 'gross_amount' => bcadd((string) $line->net_amount, $shares[$index], 2)])->save();
            }

            $this->recompute($bill, derived: true);
        });
    }

    /* ── totals ─────────────────────────────────────────────────────────── */

    /**
     * Recompute every line's covered value and cost difference from its
     * allocations, then the bill's own receipt value from its lines.
     */
    public function recompute(Bill $bill, bool $derived = false): void
    {
        $bill->load(['lines.allocations', 'lines.purchaseOrderItem', 'vatTreatment']);
        $recoverable = (bool) $bill->vatTreatment?->is_recoverable;
        $covered = '0.00';
        $reasons = [];

        foreach ($bill->lines as $line) {
            $allocated = '0';
            $value = '0.00';
            foreach ($line->allocations as $allocation) {
                $allocated = bcadd($allocated, bcsub((string) $allocation->quantity, (string) $allocation->released_quantity, 6), 6);
                $value = bcadd($value, bcsub((string) $allocation->receipt_value, (string) $allocation->released_value, 2), 2);
            }
            $full = bccomp($allocated, $line->netQuantity(), 6) === 0;
            // VAT that cannot be recovered is part of what the goods cost.
            $basis = bcadd(bcsub((string) $line->net_amount, (string) $line->credited_net, 2), $recoverable ? '0.00' : (string) $line->vat_amount, 2);
            $difference = $full ? bcsub($basis, $value, 2) : '0.00';
            $line->forceFill([
                'allocated_quantity' => $allocated,
                'receipt_value_covered' => $value,
                'cost_difference' => $difference,
                'status' => match (true) {
                    bccomp($line->netQuantity(), '0', 6) <= 0 && bccomp((string) $line->credited_quantity, '0', 6) > 0 => 'credited',
                    ! $full => 'unmatched',
                    bccomp($difference, '0.00', 2) !== 0 => 'variance',
                    default => 'matched',
                },
            ])->save();

            // What the bill claims of the order: matched value, plus anything
            // still unmatched at the order's own price so the order-level
            // checks see the whole claim.
            $unmatched = bcsub($line->netQuantity(), $allocated, 6);
            $claim = bccomp($unmatched, '0', 6) > 0
                ? bcadd($value, $this->round(bcmul($unmatched, (string) $line->purchaseOrderItem->unit_price, 10)), 2)
                : $value;
            $covered = bcadd($covered, $claim, 2);
            if (filled($line->variance_reason)) {
                $reasons[] = $line->variance_reason;
            }
        }

        if ($bill->lines->isEmpty()) {
            return;
        }
        // Written without events: this is the server stating what the lines
        // add up to, not an edit that should withdraw a verification.
        Bill::withoutEvents(fn () => $bill->forceFill([
            'receipt_value_covered' => $covered,
            'price_variance_reason' => $reasons ? mb_substr(implode('; ', array_unique($reasons)), 0, 1000) : ($derived ? $bill->price_variance_reason : null),
        ])->save());
    }

    /** Is every stated price difference explained? */
    public function unexplainedLines(Bill $bill): Collection
    {
        return $bill->lines->filter(fn (BillLine $line) => $line->isFullyAllocated()
            && bccomp(bcsub((string) $line->net_amount, (string) $line->receipt_value_covered, 2), '0.00', 2) !== 0
            && blank($line->variance_reason));
    }

    /* ── helpers ────────────────────────────────────────────────────────── */

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 6, '.', '');
    }

    /** Half-up to the cent, on a decimal string. */
    private function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $rounded = bcadd(ltrim($value, '-'), '0.005', 2);

        return ($negative && bccomp($rounded, '0', 2) !== 0 ? '-' : '').$rounded;
    }

    private function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    private function trim(string $quantity): string
    {
        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }

    /** @return never */
    private function refuse(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
