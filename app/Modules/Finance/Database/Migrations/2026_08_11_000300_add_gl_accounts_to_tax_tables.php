<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives VAT and withholding somewhere to post.
 *
 * `CostVerificationService` has priced both taxes since the tax phase landed —
 * it splits recoverable VAT off the receipt and resolves the supplier's WHT
 * category — and `JournalPostingService` then posted a two-leg entry where both
 * legs were `net_amount`. So the tax was computed, stored on the cost line, and
 * dropped on the way to the ledger: no input-VAT receivable, no WHT liability,
 * and a credit to cash that understated what actually left the bank by exactly
 * the tax.
 *
 * The account belongs on the rate row rather than in settings because it is
 * already the thing that varies together with the rate: `STD16-REC` claims
 * against Input VAT Recoverable, `STD16-NONREC` claims against nothing at all
 * (its VAT is genuinely project cost and stays inside `net_amount`), and a
 * future treatment may well want its own account. Effective dating then carries
 * the mapping for free — restating a rate cannot silently repoint last year's
 * entries.
 *
 * Nullable on purpose. A treatment with no account is the honest encoding of
 * "this tax is not separately recoverable", which is exactly the exempt,
 * out-of-scope and non-recoverable cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_treatments', function (Blueprint $table) {
            $table->foreignId('gl_account_id')->nullable()->after('claim_window_months')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });

        Schema::table('wht_categories', function (Blueprint $table) {
            $table->foreignId('gl_account_id')->nullable()->after('aggregate_monthly')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vat_treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });

        Schema::table('wht_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });
    }
};
