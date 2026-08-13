<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\TaxResolver;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * How a cost is split into expense, VAT and withholding — in one place.
 *
 * Verification and the screen that previews verification have to agree to the
 * cent, so they run the same code rather than two implementations of the same
 * rules. A preview that is computed differently from the commit is worse than
 * no preview: it invites Finance to approve a figure the ledger will not post.
 *
 * Nothing here is persisted. `attributesFor()` returns what should be written
 * and `preview()` returns the same numbers plus the journal shape they imply;
 * only `CostVerificationService` writes, and only inside its own lock.
 */
class CostTaxPricer
{
    public function __construct(private TaxResolver $taxResolver = new TaxResolver()) {}

    /**
     * The tax columns a verification should write.
     *
     * @param  array{tax_amount?: string|float, vat_treatment_id?: int, wht_category_id?: int}  $tax
     * @return array<string, mixed>
     */
    public function attributesFor(CostLine $line, array $tax): array
    {
        $attributes = array_filter([
            'vat_treatment_id' => $tax['vat_treatment_id'] ?? null,
            'wht_category_id' => $tax['wht_category_id'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('tax_amount', $tax)) {
            // Normalised to two decimals here rather than left to the column's
            // cast. A request carrying 1600 would otherwise preview as "1600" and
            // commit as "1600.00" — the same money, two strings, and a preview
            // that does not match the record it is previewing.
            $taxAmount = bcadd((string) $tax['tax_amount'], '0', 2);

            if (bccomp($taxAmount, (string) $line->amount, 2) === 1) {
                throw CostValidationException::withErrors([
                    'tax_amount' => ['Tax cannot exceed the amount on the receipt.'],
                ]);
            }

            $net = bcsub((string) $line->amount, $taxAmount, 2);

            $attributes += [
                'tax_amount' => $taxAmount,
                'net_amount' => $net,
                'base_net_amount' => bcmul($net, (string) $line->fx_rate, 2),
            ];
        } else {
            $net = (string) $line->net_amount;
        }

        return $attributes + $this->withholdingAttributes($line, $attributes, $net);
    }

    /**
     * What verifying on these choices would do, before it is done.
     *
     * The options are resolved on the cost's OWN date, not today's: both tax
     * tables are effective-dated, so offering the current rate for an August
     * receipt would price it under a rule that did not apply when it was spent.
     *
     * @param  array<string, mixed>  $tax
     * @return array<string, mixed>
     */
    public function preview(CostLine $line, array $tax): array
    {
        $on = $this->onDate($line);

        try {
            $attributes = $this->attributesFor($line, $tax);
            $error = null;
        } catch (CostValidationException $e) {
            // A preview must still render when the typed figure is impossible —
            // showing the error beside the field is the whole point of asking
            // before committing.
            $attributes = [];
            $error = $e->errors;
        }

        $gross = (string) $line->amount;
        $taxAmount = (string) ($attributes['tax_amount'] ?? $line->tax_amount ?? '0.00');
        $net = (string) ($attributes['net_amount'] ?? $line->net_amount ?? '0.00');
        $wht = (string) ($attributes['wht_amount'] ?? $line->wht_amount ?? '0.00');

        $treatmentId = $attributes['vat_treatment_id'] ?? $line->vat_treatment_id;
        $categoryId = $attributes['wht_category_id'] ?? $line->wht_category_id;

        return [
            'errors' => $error,
            'split' => [
                'gross' => $gross,
                'tax_amount' => $taxAmount,
                'net_amount' => $net,
                'wht_amount' => $wht,
                // What actually leaves the business, and the figure a supplier
                // will query if it does not match their own remittance advice.
                'payable' => bcsub(bcadd($net, $taxAmount, 2), $wht, 2),
            ],
            'legs' => $this->legs($net, $taxAmount, $wht, $treatmentId),
            'suggested' => [
                'vat_treatment_id' => $treatmentId,
                'wht_category_id' => $categoryId,
            ],
            'options' => [
                'vat_treatments' => $this->vatOptions($on),
                'wht_categories' => $this->whtOptions($on, $net),
            ],
            'payee' => $this->payeeContext($line),
        ];
    }

    /**
     * The journal this would post, in words.
     *
     * Mirrors `JournalPostingService::costLineLegs` — same order, same
     * balancing figure — so what a verifier is shown is what the ledger gets.
     * Account names are deliberately absent: the posting rule resolves those at
     * commit time and guessing them here would be a second source of truth.
     *
     * @return array<int, array<string, string>>
     */
    private function legs(string $net, string $tax, string $wht, ?int $treatmentId): array
    {
        $recoverable = $treatmentId
            && VatTreatment::whereKey($treatmentId)->value('is_recoverable');

        $legs = [
            ['side' => 'debit', 'label' => 'Expense / project WIP', 'amount' => $net],
        ];

        if (bccomp($tax, '0.00', 2) === 1) {
            $legs[] = $recoverable
                ? ['side' => 'debit', 'label' => 'Input VAT recoverable', 'amount' => $tax]
                // Non-recoverable VAT is a real cost and belongs in the expense,
                // which is why it is never split out of `net_amount` upstream.
                : ['side' => 'debit', 'label' => 'Input VAT (not recoverable)', 'amount' => $tax];
        }

        if (bccomp($wht, '0.00', 2) === 1) {
            $legs[] = ['side' => 'credit', 'label' => 'Withholding tax payable to KRA', 'amount' => $wht];
        }

        $legs[] = [
            'side' => 'credit',
            'label' => 'Cash / supplier payable',
            'amount' => bcsub(bcadd($net, $tax, 2), $wht, 2),
        ];

        return $legs;
    }

    /** @return array<int, array<string, mixed>> */
    private function vatOptions(string $on): array
    {
        return VatTreatment::effectiveOn($on)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (VatTreatment $treatment) => [
                'id' => $treatment->id,
                'code' => $treatment->code,
                'name' => $treatment->name,
                'rate_percent' => $treatment->rate_percent,
                'is_recoverable' => (bool) $treatment->is_recoverable,
                'requires_etims' => (bool) $treatment->requires_etims,
            ])->all();
    }

    /**
     * WHT options, each priced against this cost.
     *
     * The amount is shown per option rather than only for the chosen one so the
     * consequence of the choice is visible before it is made — a category whose
     * threshold this payment sits under withholds nothing, and that is a fact
     * worth seeing rather than discovering from a zero afterwards.
     *
     * @return array<int, array<string, mixed>>
     */
    private function whtOptions(string $on, string $net): array
    {
        return WhtCategory::effectiveOn($on)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (WhtCategory $category) => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'rate_percent' => $category->rate_percent,
                'residency' => $category->residency,
                'threshold_amount' => $category->threshold_amount,
                'would_withhold' => $this->taxResolver->withholding($net, $category),
            ])->all();
    }

    /**
     * Why the suggestion is what it is.
     *
     * Withholding follows the supplier's tax identity, so a verifier looking at
     * an unexpected figure needs to see the supplier it was derived from — and,
     * more usefully, when there is no supplier record at all and therefore
     * nothing was withheld.
     *
     * @return array<string, mixed>
     */
    private function payeeContext(CostLine $line): array
    {
        $supplier = $this->supplierFor($line);

        return [
            'name' => $line->payee_name ?: $supplier?->supplier_name,
            'is_supplier' => $supplier !== null,
            'vat_status' => $supplier?->vat_status,
            'residency' => $supplier?->residency,
            'note' => $supplier
                ? null
                : 'No supplier record on this payee, so nothing is withheld automatically.',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withholdingAttributes(CostLine $line, array $attributes, string $net): array
    {
        $categoryId = $attributes['wht_category_id'] ?? $line->wht_category_id;

        $category = $categoryId
            ? WhtCategory::find($categoryId)
            : $this->taxResolver->whtCategoryFor(
                $this->supplierFor($line),
                $line->expense_code_id ? ExpenseCode::find($line->expense_code_id) : null,
                $this->onDate($line),
            );

        if (! $category) {
            return [];
        }

        return [
            'wht_category_id' => $category->id,
            'wht_amount' => $this->taxResolver->withholding($net, $category),
        ];
    }

    /**
     * Only a payee recorded as a supplier carries tax identity — an employee
     * reimbursed for a taxi is not withheld against, and `payee_types` already
     * states which kinds require a supplier record.
     */
    private function supplierFor(CostLine $line): ?Supplier
    {
        if (! $line->payee_id || ! $line->payee_type_id) {
            return null;
        }

        $isSupplier = DB::table('payee_types')
            ->where('id', $line->payee_type_id)
            ->where('requires_supplier_record', true)
            ->exists();

        return $isSupplier ? Supplier::find($line->payee_id) : null;
    }

    private function onDate(CostLine $line): string
    {
        return (string) ($line->incurred_at ?? now()->toDateString());
    }
}
