<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Joins procurement's payment methods to Finance's payment sources.
 *
 * The two tables are the same idea, built twice:
 *
 * | | `payment_sources` (Finance) | `payment_methods` (Procurement) |
 * |---|---|---|
 * | Rows | PC-MAIN, BANK-MAIN, BANK-ALT, MPESA, CARD, AP | Bank Transfer, Cash, Check, Credit Card, Mobile Money, Equity Bank, NCBA Bank |
 * | GL account | every row | none |
 * | Created by | a seeder that is the authority | an unauthenticated-shaped POST endpoint anyone may call |
 * | Used by | petty cash, payroll, spend vouchers | bill payments only |
 *
 * `PaymentSourceSeeder` states the principle outright — "petty cash, bank,
 * mobile money and card are PAYMENT METHODS, not expense categories… each is a
 * row here carrying its own GL account, which is what lets one posting engine
 * handle all of them identically". That is exactly the job `payment_methods`
 * was doing in parallel, without the GL account, which is why paying a supplier
 * bill could not post anywhere: there was no account to credit. The `AP`
 * source was seeded specifically so a credit purchase would settle through the
 * same engine as a cash one, and procurement has never referenced it.
 *
 * Rather than drop a table that `bill_payments` has foreign keys into, this
 * makes `payment_sources` the authority the existing rows resolve THROUGH. The
 * method rows stay — they are what WNG's staff recognise, and "Equity Bank" is
 * more use on a payment screen than "Bank – Secondary" — but each one now names
 * the source whose ledger account the money actually left.
 *
 * Two names in that list are banks WNG holds accounts with rather than methods
 * of paying. They map onto the two seeded bank sources in the order the seeder
 * lists them; Finance renaming BANK-MAIN to "Equity" is then a data change, and
 * that is the point of the source carrying the account rather than the method.
 */
return new class extends Migration
{
    /** Procurement method name → finance payment source code. */
    private const MAPPING = [
        'Bank Transfer' => 'BANK-MAIN',
        'Check' => 'BANK-MAIN',
        'Equity Bank' => 'BANK-MAIN',
        'NCBA Bank' => 'BANK-ALT',
        'Cash' => 'PC-MAIN',
        'Mobile Money' => 'MPESA',
        'Credit Card' => 'CARD',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('payment_methods', 'payment_source_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                // Nullable, and restrictOnDelete rather than nullOnDelete: a
                // method that has settled bills must not quietly lose the
                // account those payments were made from.
                $table->foreignId('payment_source_id')->nullable()->after('method_name')
                    ->constrained('payment_sources')->restrictOnDelete();
            });
        }

        $sources = DB::table('payment_sources')->pluck('id', 'code');

        foreach (self::MAPPING as $method => $sourceCode) {
            if (! isset($sources[$sourceCode])) {
                continue;
            }

            DB::table('payment_methods')
                ->where('method_name', $method)
                ->whereNull('payment_source_id')
                ->update(['payment_source_id' => $sources[$sourceCode]]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_methods', 'payment_source_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropForeign(['payment_source_id']);
                $table->dropColumn('payment_source_id');
            });
        }
    }
};
