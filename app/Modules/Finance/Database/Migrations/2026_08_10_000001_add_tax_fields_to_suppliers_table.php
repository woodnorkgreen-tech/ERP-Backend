<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax identity on the supplier master (GL plan Phase 3, brief §8).
 *
 * The table belongs to ProcurementStores, but these columns are Finance's: they
 * exist so WHT and VAT can be computed at posting time from the supplier's own
 * category rather than from a rate somebody typed on a voucher. The migration
 * lives with Finance for that reason, and runs after the January create.
 *
 * Every column is nullable. Six suppliers already exist with no tax data, and a
 * required field here would make the procurement form unusable until somebody
 * backfilled KRA PINs — the classification is captured going forward and
 * enriched as invoices arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // The name on the KRA certificate, which routinely differs from the
            // trading name staff know the supplier by. Both are needed: one to
            // search, one to file.
            $table->string('legal_name')->nullable()->after('supplier_name');

            // Unique because a PIN identifies one taxpayer. Nullable columns
            // permit unlimited NULLs under a unique index, so the existing
            // untaxed rows are unaffected — only a genuine duplicate is refused.
            $table->string('kra_pin', 20)->nullable()->unique()->after('legal_name');

            $table->enum('vat_status', ['registered', 'not_registered', 'unknown'])
                ->default('unknown')
                ->after('kra_pin');

            // Whether this supplier issues eTIMS-compliant invoices by default.
            // Input VAT is only recoverable against one, so this drives whether
            // a treatment's recoverability can actually be claimed.
            $table->boolean('etims_default')->default(false)->after('vat_status');

            // WHT rates differ for non-residents, and wht_categories carries a
            // residency column to match against.
            $table->enum('residency', ['resident', 'non_resident'])
                ->default('resident')
                ->after('etims_default');

            $table->foreignId('default_vat_treatment_id')->nullable()
                ->after('residency')
                ->constrained('vat_treatments')->nullOnDelete();

            $table->foreignId('wht_category_id')->nullable()
                ->after('default_vat_treatment_id')
                ->constrained('wht_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wht_category_id');
            $table->dropConstrainedForeignId('default_vat_treatment_id');
            $table->dropUnique(['kra_pin']);
            $table->dropColumn([
                'legal_name', 'kra_pin', 'vat_status', 'etims_default', 'residency',
            ]);
        });
    }
};
