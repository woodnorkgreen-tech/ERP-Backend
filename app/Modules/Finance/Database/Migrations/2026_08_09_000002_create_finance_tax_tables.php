<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT and withholding-tax reference data.
 *
 * Both tables are EFFECTIVE-DATED rather than simply versioned. A rate table
 * without effective dates silently restates history the moment a rate changes:
 * last year's transactions would revalue at this year's rate. Lookups therefore
 * always resolve against the transaction date, never "the current row".
 *
 * Rates are never hardcoded anywhere in the codebase — the brief requires them
 * to be accountant-approved and configurable before go-live.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A treatment carries both the rate and its recoverability, because those
        // two together are what decides the project cost: recoverable VAT is a
        // receivable from KRA and must be stripped out of project cost at entry,
        // while non-recoverable VAT genuinely is part of it.
        Schema::create('vat_treatments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->decimal('rate_percent', 6, 3)->default(0);
            $table->boolean('is_recoverable')->default(false);
            $table->boolean('requires_etims')->default(false);

            // KRA's input-tax claim window, in months, kept configurable rather
            // than assumed — it is a policy value, not a constant of nature.
            $table->unsignedSmallInteger('claim_window_months')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'effective_from'], 'vat_treatment_code_effective_unique');
            $table->index(['effective_from', 'effective_to']);
        });

        // WHT is assessed per supplier per month, not per petty payment, so the
        // threshold lives here and the aggregation happens at reporting time.
        Schema::create('wht_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->decimal('rate_percent', 6, 3)->default(0);
            $table->string('residency', 16)->default('resident');   // resident | non_resident
            $table->decimal('threshold_amount', 14, 2)->nullable();
            $table->boolean('aggregate_monthly')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'effective_from'], 'wht_category_code_effective_unique');
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wht_categories');
        Schema::dropIfExists('vat_treatments');
    }
};
