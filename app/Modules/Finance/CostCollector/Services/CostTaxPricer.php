<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\Models\WhtCategory;
use App\Modules\Finance\Services\TaxResolver;
use App\Modules\ProcurementStores\Models\Supplier;
use Carbon\Carbon;
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
     * @param  array{tax_amount?: string|float, vat_treatment_id?: int, wht_category_id?: int, supplier_invoice_no?: string, etims_invoice_no?: string, tax_point_date?: string}  $tax
     * @return array<string, mixed>
     */
    public function attributesFor(CostLine $line, array $tax, bool $enforceDocument = true): array
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

        $attributes += $this->withholdingAttributes($line, $attributes, $net);

        return $attributes + $this->documentAttributes($line, $attributes, $tax, $enforceDocument);
    }

    /**
     * The claim reference, and the refusal to post a claim without one.
     *
     * A recoverable input-VAT line is a claim against KRA. KRA matches it to the
     * supplier's eTIMS record by control number and PIN; a claim carrying
     * neither is not a claim, it is an amount in a column that will be
     * disallowed on assessment and, because the six-month window will have run
     * by then, cannot be re-filed. Refusing it at verification is the only point
     * at which it is still cheap to fix — the person clicking Verify has the
     * document in their hand.
     *
     * The gate is deliberately narrow. It fires only when the chosen treatment
     * is BOTH recoverable and eTIMS-bearing AND actual VAT was priced onto the
     * line. Zero-rated purchases, exempt supplies, out-of-scope spend and the
     * non-recoverable treatment all pass untouched, because none of them claim
     * anything.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $tax
     * @return array<string, mixed>
     */
    private function documentAttributes(CostLine $line, array $attributes, array $tax, bool $enforce = true): array
    {
        $document = array_filter([
            'supplier_invoice_no' => $tax['supplier_invoice_no'] ?? null,
            'etims_invoice_no' => $tax['etims_invoice_no'] ?? null,
            // Defaulted to the cost's own date rather than left null: for the
            // overwhelming majority of spend the receipt date IS the incurred
            // date, and a null here would silently drop the line out of every
            // claim schedule, which is the failure that does not announce
            // itself.
            'tax_point_date' => $tax['tax_point_date'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        // Defaulted for every line, not only claimable ones. A tax point that is
        // null drops the row out of every schedule silently, and "it did not
        // appear on the return" is the one VAT error nobody notices until an
        // assessment.
        if (! $line->tax_point_date) {
            $document['tax_point_date'] ??= substr($this->onDate($line), 0, 10);
        }

        $supplier = $this->supplierFor($line);

        // Snapshotted, never joined. See the migration: a filed return must keep
        // showing the PIN it was filed under.
        //
        // A typed PIN wins over the supplier record because plenty of claimable
        // spend has no supplier record at all — a hardware shop paid out of
        // petty cash prints its PIN on the eTIMS receipt and will never be a
        // vendor master. Requiring a supplier record before VAT could be
        // reclaimed would have made the commonest claimable purchase in the
        // business unclaimable, and the workaround would have been to stop
        // claiming.
        $typedPin = $tax['supplier_pin'] ?? null;

        if (filled($typedPin)) {
            $document['supplier_pin'] = strtoupper(trim((string) $typedPin));
        } elseif ($supplier?->kra_pin && ! $line->supplier_pin) {
            $document['supplier_pin'] = $supplier->kra_pin;
        }

        $treatmentId = $attributes['vat_treatment_id'] ?? $line->vat_treatment_id;
        $taxAmount = (string) ($attributes['tax_amount'] ?? $line->tax_amount ?? '0.00');

        if (! $treatmentId || bccomp($taxAmount, '0.00', 2) !== 1) {
            return $document;
        }

        $treatment = VatTreatment::find($treatmentId);

        if (! $treatment?->is_recoverable || ! $treatment->requires_etims) {
            return $document;
        }

        $errors = [];

        if (blank($document['etims_invoice_no'] ?? $line->etims_invoice_no)) {
            $errors['etims_invoice_no'] = [
                "Recoverable VAT under {$treatment->code} must carry the supplier's eTIMS invoice number. "
                . 'Without it KRA cannot match the claim and it will be disallowed.',
            ];
        }

        if (blank($document['supplier_pin'] ?? $line->supplier_pin)) {
            $errors['supplier_pin'] = [
                $supplier
                    ? "Supplier {$supplier->supplier_name} has no KRA PIN on record. Add it to the supplier, or enter the PIN from the receipt."
                    : 'A recoverable VAT claim needs the supplier\'s KRA PIN. Enter the one printed on the eTIMS receipt.',
            ];
        }

        if ($errors && $enforce) {
            throw CostValidationException::withErrors($errors);
        }

        return $document;
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
            $error = $e->errors;

            // Re-price with the document gate off. A missing eTIMS number says
            // nothing about what the split should be, and blanking the whole
            // preview over it would hide the numbers behind an unrelated
            // omission — the verifier could no longer see the VAT they are
            // being asked to evidence. An impossible tax figure still throws
            // here, because there is genuinely no split to show for it.
            try {
                $attributes = $this->attributesFor($line, $tax, false);
            } catch (CostValidationException) {
                $attributes = [];
            }
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
            // Resolved from what the verifier is CHOOSING, not from what priced
            // successfully. When pricing threw — and the commonest reason it now
            // throws is the missing eTIMS number — `$attributes` is empty, and
            // reading the treatment from there would tell the form no document
            // is required at the exact moment it is being refused for the want
            // of one.
            'document' => $this->documentContext(
                $line,
                $tax['vat_treatment_id'] ?? $treatmentId,
                (string) ($tax['tax_amount'] ?? $taxAmount),
                $tax,
            ),
        ];
    }

    /**
     * Whether this verification needs claim evidence, and what it already has.
     *
     * Returned from the preview so the requirement appears on the form as a
     * field, not as a 422 after the verifier has committed to the numbers. The
     * claim deadline is included because it is the fact that decides urgency:
     * a line whose window closes in three weeks is a different conversation
     * from one with five months left.
     *
     * @param  array<string, mixed>  $tax
     * @return array<string, mixed>
     */
    private function documentContext(CostLine $line, ?int $treatmentId, string $taxAmount, array $tax): array
    {
        $treatment = $treatmentId ? VatTreatment::find($treatmentId) : null;

        $required = (bool) $treatment?->is_recoverable
            && (bool) $treatment?->requires_etims
            && bccomp($taxAmount, '0.00', 2) === 1;

        $taxPoint = $tax['tax_point_date']
            ?? $line->tax_point_date?->toDateString()
            ?? substr($this->onDate($line), 0, 10);

        $window = (int) ($treatment?->claim_window_months ?: 0);

        return [
            'required' => $required,
            'reason' => $required
                ? "{$treatment->code} claims input tax back from KRA, which matches it to the supplier's eTIMS record."
                : null,
            'supplier_invoice_no' => $tax['supplier_invoice_no'] ?? $line->supplier_invoice_no,
            'etims_invoice_no' => $tax['etims_invoice_no'] ?? $line->etims_invoice_no,
            'supplier_pin' => $line->supplier_pin ?? $this->supplierFor($line)?->kra_pin,
            'tax_point_date' => $taxPoint,
            'claim_deadline' => $window
                ? Carbon::parse($taxPoint)->addMonthsNoOverflow($window)->toDateString()
                : null,
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
