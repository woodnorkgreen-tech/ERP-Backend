<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Models\VatTreatment;
use InvalidArgumentException;

/**
 * Prices an invoice's lines, and totals the header from them.
 *
 * ## Why the header is derived rather than entered
 *
 * `project_invoices.subtotal` / `tax_amount` / `total_amount` were typed by a
 * person, so the tax on a client invoice was an assertion with no rate behind
 * it. Meanwhile the cost side has priced supplier tax off effective-dated
 * `vat_treatments` rows since the tax masters were seeded. The two halves of
 * the same tax were being handled to completely different standards, and the
 * half WNG *owes* was the loose one.
 *
 * Totals are therefore computed here and written back to the header on every
 * change, which keeps every existing reader — the receivables screen, the
 * billing-basis cap that stops a project being over-billed, the invoice
 * allocation balance — working against columns that can no longer disagree with
 * the lines beneath them.
 *
 * ## Why the rate is resolved by date and then stored as money
 *
 * The line keeps `vat_treatment_id` (a reference to a rule) and `tax_amount`
 * (the money that rule produced on the day). Storing the rate instead would
 * freeze today's percentage onto historical invoices; storing only the treatment
 * would restate an old invoice the day a rate changes. Keeping both means an
 * invoice raised in March still shows March's tax, and still says which rule
 * produced it.
 *
 * The date used is the INVOICE DATE, not today: an invoice dated into last month
 * is charged at last month's rate, which is what the client's copy will show.
 */
class InvoicePricer
{
    /**
     * Price one line from its quantity, unit price and treatment.
     *
     * Rounding is applied once, to the line's tax, rather than to a running
     * total — so an invoice's tax equals the sum of its lines' tax exactly, and
     * a client checking one line against the total finds them consistent.
     *
     * @return array{net_amount: string, tax_amount: string, total_amount: string}
     */
    public function priceLine(
        string|float $quantity,
        string|float $unitPrice,
        ?VatTreatment $treatment,
    ): array {
        $net = $this->money(bcmul(
            $this->decimal($quantity, 3),
            $this->decimal($unitPrice, 2),
            6,
        ));

        if (bccomp($net, '0.00', 2) < 0) {
            throw new InvalidArgumentException(
                'An invoice line cannot be negative. Raise a credit note to reduce an invoice.'
            );
        }

        $rate = $treatment ? $this->decimal($treatment->rate_percent, 3) : '0.000';
        $tax = $this->money(bcdiv(bcmul($net, $rate, 6), '100', 6));

        return [
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => bcadd($net, $tax, 2),
        ];
    }

    /**
     * The treatment in force on the invoice's date, for a given treatment id.
     *
     * Returns null for a line that names no treatment — zero-rated, exempt and
     * out-of-scope revenue are all legitimate, and a null treatment carries no
     * tax rather than being an error.
     */
    public function treatmentFor(?int $treatmentId, string $onDate): ?VatTreatment
    {
        if (! $treatmentId) {
            return null;
        }

        $treatment = VatTreatment::query()
            ->effectiveOn($onDate)
            ->whereKey($treatmentId)
            ->first();

        if (! $treatment) {
            throw new InvalidArgumentException(
                'That Value Added Tax treatment is not in force on the invoice date. '
                . 'Choose one that applies on ' . $onDate . '.'
            );
        }

        return $treatment;
    }

    /**
     * Recompute the header from the lines, and persist it.
     *
     * Called after any line change. The invoice is saved rather than returned
     * unsaved because every caller wants it stored — leaving that to the caller
     * is how a header and its lines drift apart.
     */
    public function retotal(ProjectInvoice $invoice): ProjectInvoice
    {
        $lines = $invoice->lines()->get();

        $subtotal = $lines->reduce(
            fn (string $carry, $line) => bcadd($carry, $this->money($line->net_amount), 2),
            '0.00',
        );
        $tax = $lines->reduce(
            fn (string $carry, $line) => bcadd($carry, $this->money($line->tax_amount), 2),
            '0.00',
        );

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => bcadd($subtotal, $tax, 2),
        ])->save();

        return $invoice;
    }

    /** Two-decimal money, as a string, so nothing here touches a float. */
    private function money(string|float|null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function decimal(string|float|null $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
