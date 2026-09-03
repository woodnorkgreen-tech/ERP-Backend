<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence a KRA input-VAT claim actually stands on.
 *
 * `vat_treatments` has carried `requires_etims` and `claim_window_months` since
 * the tax masters were seeded, and `finance_settings` states outright that the
 * six-month window "drives the eTIMS gap and VAT claim schedule reports". Those
 * reports could not be written: the system priced VAT to the cent and recorded
 * nothing about the document that VAT came off. `cost_lines.evidence` holds
 * uploaded file paths — a scan of a receipt is not a claim reference.
 *
 * A claim needs four facts this adds:
 *
 *   supplier_invoice_no  what the supplier called it, for their own query
 *   etims_invoice_no     the eTIMS/ETR control number KRA matches against
 *   supplier_pin         the supplier's PIN as it stood when verified
 *   tax_point_date       the date on the supplier's document
 *
 * `supplier_pin` is snapshotted rather than joined to `suppliers.kra_pin`. A PIN
 * on a filed return is a historical assertion: if the supplier record is later
 * corrected, last quarter's return must still show what was claimed under, or
 * the schedule stops reconciling to what was filed.
 *
 * `tax_point_date` is separate from `incurred_at` on purpose. The claim window
 * runs from the supplier's document date, not from the day a project consumed
 * the material — for a Stores issue those are routinely months apart, and using
 * the wrong one either forfeits a live claim or files an expired one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->string('supplier_invoice_no', 64)->nullable()->after('evidence');
            $table->string('etims_invoice_no', 64)->nullable()->after('supplier_invoice_no');
            $table->string('supplier_pin', 20)->nullable()->after('etims_invoice_no');
            $table->date('tax_point_date')->nullable()->after('supplier_pin');

            // The claim schedule reads recoverable lines in a date range, and
            // the eTIMS gap report reads the same set filtered to a null
            // reference. Both are covered by this one index.
            $table->index(['vat_treatment_id', 'tax_point_date'], 'cost_lines_vat_claim_idx');

            // The WHT return groups by payee within a month. `payee_id` alone
            // is ambiguous across payee types, so both lead the key.
            $table->index(['payee_type_id', 'payee_id', 'incurred_at'], 'cost_lines_wht_payee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->dropIndex('cost_lines_vat_claim_idx');
            $table->dropIndex('cost_lines_wht_payee_idx');
            $table->dropColumn([
                'supplier_invoice_no',
                'etims_invoice_no',
                'supplier_pin',
                'tax_point_date',
            ]);
        });
    }
};
