<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One payment may settle more than one supplier invoice.
 *
 * `bill_payments.disbursement_id` was UNIQUE, which encoded the assumption that
 * a payment document settles exactly one invoice. That was true while the
 * procurement screen recorded a separate payment per invoice — and it was also
 * the reason the transaction fee had nowhere to live: one bank transfer paying
 * three invoices became three payment documents, and a single charge could only
 * be split between them arbitrarily or dropped.
 *
 * A transfer is now one `payments` row with a `bill_payments` allocation per
 * invoice, so the column becomes an ordinary index.
 *
 * What the unique constraint was really guarding — one disbursement's cash being
 * claimed twice — is not lost: the allocations are written together from a
 * single amount inside one transaction, and their total is the payment's own
 * `amount`, which the ledger and the float are reconciled against.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The foreign key sits on top of the unique index, so it has to come off
        // first and go back afterwards. Both are looked up rather than named:
        // the databases here were built by different routes and do not agree on
        // the generated constraint names.
        $foreignKeys = $this->constraintNames(referenced: true);
        foreach ($foreignKeys as $name) {
            DB::statement("ALTER TABLE bill_payments DROP FOREIGN KEY `{$name}`");
        }

        foreach ($this->uniqueIndexNames() as $name) {
            DB::statement("ALTER TABLE bill_payments DROP INDEX `{$name}`");
        }

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->index('disbursement_id');
            $table->foreign('disbursement_id')->references('id')->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach ($this->constraintNames(referenced: true) as $name) {
            DB::statement("ALTER TABLE bill_payments DROP FOREIGN KEY `{$name}`");
        }

        // A database that has since recorded a transfer against several invoices
        // cannot take the unique back. Saying so is better than failing on a
        // duplicate-key error halfway through a rollback.
        $shared = DB::table('bill_payments')
            ->whereNotNull('disbursement_id')
            ->groupBy('disbursement_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($shared > 0) {
            throw new RuntimeException(
                "Cannot restore the one-invoice-per-payment constraint: {$shared} payment(s) settle several "
                . 'invoices. Split them into separate payments first.'
            );
        }

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropIndex(['disbursement_id']);
            $table->unique('disbursement_id');
            $table->foreign('disbursement_id')->references('id')->on('payments')->restrictOnDelete();
        });
    }

    /** @return array<int, string> */
    private function constraintNames(bool $referenced): array
    {
        return array_map(
            fn ($row) => $row->CONSTRAINT_NAME,
            DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_payments'
                   AND COLUMN_NAME = 'disbursement_id'
                   AND REFERENCED_TABLE_NAME IS " . ($referenced ? 'NOT NULL' : 'NULL')
            ),
        );
    }

    /** @return array<int, string> */
    private function uniqueIndexNames(): array
    {
        return array_map(
            fn ($row) => $row->INDEX_NAME,
            DB::select(
                "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_payments'
                   AND COLUMN_NAME = 'disbursement_id' AND NON_UNIQUE = 0"
            ),
        );
    }
};
