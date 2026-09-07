<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes down which document a cash ledger entry came from.
 *
 * `LedgerEntry` has carried `sourceType` and `sourceId` since it was written —
 * `creditForTopUp` sets them, `debitForDisbursement` sets them, and
 * `LedgerService::post()` reads them to stamp the balance projection's
 * `last_transaction_*`. But `toRow()` never included them, so the columns did
 * not exist and the ledger could only be searched by the shape of its reference
 * string.
 *
 * That became a correctness problem the moment a disbursement stopped always
 * debiting the tin. A payment made from a bank account or M-Pesa now posts NO
 * cash entry, which is right — the money did not come out of the float. Voiding
 * one must therefore not credit the float either, and the only way to know is to
 * ask whether this disbursement ever debited it. `PettyCashService::voidDisbursement()`
 * asks exactly that and was querying columns that were not there, so every void
 * failed.
 *
 * ## Backfill
 *
 * Recoverable from the reference, which has always encoded it:
 * `TOP-000012` is a top-up, `PCR-000007` and `PCR-000007-VOID` a disbursement.
 * Anything else — the custom entries written for adjustments — is left null,
 * which is the honest answer rather than a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_ledger_entries')) {
            return;
        }

        Schema::table('petty_cash_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('petty_cash_ledger_entries', 'source_type')) {
                // A string rather than an enum: the ledger's own rule is that a
                // set is an enum only when it is fixed, and what can pay cash out
                // is not — a supplier payment is already a third kind of source.
                $table->string('source_type', 32)->nullable()->after('reference_number');
            }

            if (! Schema::hasColumn('petty_cash_ledger_entries', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
        });

        // Separate statement: an index cannot be added in the same Blueprint
        // pass that creates the columns on every MySQL version in play.
        Schema::table('petty_cash_ledger_entries', function (Blueprint $table) {
            $indexes = collect(DB::select('SHOW INDEX FROM petty_cash_ledger_entries'))
                ->pluck('Key_name')->unique();

            if (! $indexes->contains('pcle_source_idx')) {
                $table->index(['source_type', 'source_id', 'type'], 'pcle_source_idx');
            }
        });

        DB::statement(<<<'SQL'
            UPDATE petty_cash_ledger_entries
            SET source_type = 'top_up',
                source_id = CAST(SUBSTRING(reference_number, 5) AS UNSIGNED)
            WHERE source_type IS NULL
              AND reference_number LIKE 'TOP-%'
        SQL);

        // REV-TOP- reverses a top-up and is top-up sourced, matching the
        // reasoning already written into LedgerEntry::reversalForTopUp().
        DB::statement(<<<'SQL'
            UPDATE petty_cash_ledger_entries
            SET source_type = 'top_up',
                source_id = CAST(SUBSTRING(reference_number, 9) AS UNSIGNED)
            WHERE source_type IS NULL
              AND reference_number LIKE 'REV-TOP-%'
        SQL);

        // Covers both 'PCR-000007' and 'PCR-000007-VOID': CAST stops at the
        // first non-digit, so the suffix is ignored.
        DB::statement(<<<'SQL'
            UPDATE petty_cash_ledger_entries
            SET source_type = 'disbursement',
                source_id = CAST(SUBSTRING(reference_number, 5) AS UNSIGNED)
            WHERE source_type IS NULL
              AND reference_number LIKE 'PCR-%'
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('petty_cash_ledger_entries')) {
            return;
        }

        Schema::table('petty_cash_ledger_entries', function (Blueprint $table) {
            $indexes = collect(DB::select('SHOW INDEX FROM petty_cash_ledger_entries'))
                ->pluck('Key_name')->unique();

            if ($indexes->contains('pcle_source_idx')) {
                $table->dropIndex('pcle_source_idx');
            }

            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
