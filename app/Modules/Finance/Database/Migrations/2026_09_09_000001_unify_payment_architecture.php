<?php

use App\Modules\Finance\Support\PaymentMethods;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One payment document, one paying-account master, method separated from source.
 *
 * `petty_cash_disbursements` had already stopped being about petty cash:
 * PettyCashService branches on the payment source and skips the float balance
 * check, the top-up allocation and the cash ledger entry for any non-float
 * source, so a bank payment was already recordable — as a row in a table, a
 * service, a module and a route namespace that all say "petty cash". Renaming it
 * is what makes the capability findable, and what stops a second bank-payment
 * engine from being written beside it.
 *
 * The payment_method enum carried five bank names (equity, stanbic, ncba, kcb,
 * family). Those are accounts, so they move to payment_sources; what is left is
 * a list of transmission methods. Every live row is `cash`, so no value is
 * remapped and nothing is lost.
 */
return new class extends Migration
{
    /** Old procurement payment_methods.method_name => canonical method code. */
    private const METHOD_NAME_MAP = [
        'Bank Transfer' => 'bank_transfer',
        'Cash' => 'cash',
        'Check' => 'cheque',
        'Cheque' => 'cheque',
        'Credit Card' => 'card',
        'Mobile Money' => 'mpesa',
        // The three rows that were accounts, not methods. The account they named
        // is already on bill_payments.payment_source_id, so only the transmission
        // method is inferred here.
        'Equity Bank' => 'bank_transfer',
        'NCBA Bank' => 'bank_transfer',
        'Main Petty Cash Float' => 'cash',
    ];

    public function up(): void
    {
        $methods = "'" . implode("','", PaymentMethods::values()) . "'";

        // ------------------------------------------------------------------
        // 1. The paying-account master gains the bank identities it was missing.
        // ------------------------------------------------------------------
        // The real bank names lived in procurement's payment_methods table, which
        // mapped "Equity Bank" onto BANK-MAIN and "NCBA Bank" onto BANK-ALT. That
        // mapping is the authority for these two renames.
        DB::table('payment_sources')->where('code', 'BANK-MAIN')
            ->update(['name' => 'Equity Bank – Operating Account']);
        DB::table('payment_sources')->where('code', 'BANK-ALT')
            ->update(['name' => 'NCBA Bank – Operations Account']);

        /*
         * Stanbic, KCB and Family Bank were offered as petty-cash "payment
         * methods" but never used. They are carried over as accounts rather than
         * dropped, and left INACTIVE: whether WNG banks with them is Finance's
         * fact to assert from the admin screen, not a migration's to invent.
         *
         * Only for a database that already has a payment-source list. On an
         * empty one this migration runs before any seeder, so inserting here
         * would take the first ids and make the default account an inactive bank
         * nobody uses. PaymentSourceSeeder owns the list for a fresh install.
         */
        $bankGl = DB::table('payment_sources')->where('code', 'BANK-MAIN')->value('gl_account_id');
        if ($bankGl !== null) {
            foreach ([
                ['BANK-STANBIC', 'Stanbic Bank'],
                ['BANK-KCB', 'KCB Bank'],
                ['BANK-FAMILY', 'Family Bank'],
            ] as [$code, $name]) {
                DB::table('payment_sources')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'type' => 'bank',
                        'gl_account_id' => $bankGl,
                        'currency' => 'KES',
                        'is_active' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        // ------------------------------------------------------------------
        // 2. bill_payments stops pointing at the mixed method/account list.
        // ------------------------------------------------------------------
        if (! Schema::hasColumn('bill_payments', 'payment_method')) {
            Schema::table('bill_payments', function (Blueprint $table) {
                $table->enum('payment_method', PaymentMethods::values())
                    ->nullable()->after('payment_source_id');
            });
        }

        if (Schema::hasTable('payment_methods')) {
            foreach (self::METHOD_NAME_MAP as $name => $code) {
                DB::statement(
                    'UPDATE bill_payments bp JOIN payment_methods pm ON pm.id = bp.payment_method_id
                     SET bp.payment_method = ? WHERE pm.method_name = ?',
                    [$code, $name],
                );
            }
        }
        // An account-shaped method row named no transmission method of its own;
        // anything still unmapped falls back to the account's own kind.
        DB::statement(
            "UPDATE bill_payments bp JOIN payment_sources ps ON ps.id = bp.payment_source_id
             SET bp.payment_method = CASE ps.type
                 WHEN 'petty_cash' THEN 'cash'
                 WHEN 'mobile_money' THEN 'mpesa'
                 WHEN 'card' THEN 'card'
                 ELSE 'bank_transfer' END
             WHERE bp.payment_method IS NULL",
        );

        if (Schema::hasColumn('bill_payments', 'payment_method_id')) {
            /*
             * The constraint is looked up rather than named. A database built by
             * replaying every migration has the foreign key the 2026-01 bills
             * migration declared; the long-lived databases do not, because the
             * column predates it there. dropConstrainedForeignId fails on the
             * second, and a bare dropColumn fails on the first.
             */
            foreach (DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bill_payments'
                   AND COLUMN_NAME = 'payment_method_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
            ) as $constraint) {
                DB::statement("ALTER TABLE bill_payments DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
            }

            Schema::table('bill_payments', function (Blueprint $table) {
                $table->dropColumn('payment_method_id');
            });
        }
        Schema::dropIfExists('payment_methods');

        // ------------------------------------------------------------------
        // 3. The disbursement becomes the payment.
        // ------------------------------------------------------------------
        if (Schema::hasTable('petty_cash_disbursements')) {
            DB::statement("ALTER TABLE petty_cash_disbursements MODIFY payment_method ENUM($methods) NOT NULL DEFAULT 'cash'");
            Schema::rename('petty_cash_disbursements', 'payments');
        }
        DB::statement("ALTER TABLE petty_cash_top_ups MODIFY payment_method ENUM($methods) NOT NULL DEFAULT 'cash'");

        Schema::table('payments', function (Blueprint $table) {
            // The document number the ERP owns. There was none: `transaction_code`
            // held the M-Pesa or bank reference, so the payee's reference was the
            // only identifier a payment had.
            $table->string('payment_no', 32)->nullable()->unique()->after('id');

            // Whether the money is spent or merely advanced. An advance is not yet
            // an expense, and until now nothing on the record said which it was.
            $table->enum('payment_type', ['direct', 'advance', 'retirement', 'refund'])
                ->default('direct')->after('payment_no');

            $table->string('payee_type', 32)->nullable()->after('receiver');
            $table->unsignedBigInteger('payee_id')->nullable()->after('payee_type');

            $table->index(['payment_type', 'status']);
        });

        // `receiver` and `transaction_code` are renamed to the standard terms so
        // one word means one thing across Finance, Procurement and Stores.
        // `transaction_code` was especially misleading: it holds the payee's
        // M-Pesa or bank reference, so before payment_no existed the external
        // reference was the only identifier a payment had.
        if (Schema::hasColumn('payments', 'receiver')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('receiver', 'payee_name');
                $table->renameColumn('transaction_code', 'external_reference');
            });
        }
        if (Schema::hasColumn('petty_cash_top_ups', 'transaction_code')) {
            Schema::table('petty_cash_top_ups', function (Blueprint $table) {
                $table->renameColumn('transaction_code', 'external_reference');
            });
        }

        $this->backfillPaymentNumbers();
    }

    /**
     * Every existing payment gets a number, ordered by when the money moved.
     *
     * Numbered per calendar year of the disbursement date so the sequence a
     * person reads matches the year the payment belongs to.
     */
    private function backfillPaymentNumbers(): void
    {
        $counters = [];

        DB::table('payments')->orderBy('date_disbursed')->orderBy('id')
            ->select('id', 'date_disbursed', 'created_at')->each(function ($row) use (&$counters) {
                $year = substr((string) ($row->date_disbursed ?? $row->created_at), 0, 4) ?: date('Y');
                $counters[$year] = ($counters[$year] ?? 0) + 1;

                DB::table('payments')->where('id', $row->id)->update([
                    'payment_no' => sprintf('PAY-%s-%04d', $year, $counters[$year]),
                ]);
            });

        // Hand the sequence over where the backfill stopped, or the first live
        // payment of each year would be issued a number already in use.
        foreach ($counters as $year => $used) {
            DB::table('document_sequences')->updateOrInsert(
                ['prefix' => 'PAY', 'period' => (string) $year],
                ['next_number' => $used + 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $legacy = "'cash','mpesa','equity','stanbic','ncba','kcb','family','bank_transfer','other'";

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('payee_name', 'receiver');
            $table->renameColumn('external_reference', 'transaction_code');
        });
        Schema::table('petty_cash_top_ups', function (Blueprint $table) {
            $table->renameColumn('external_reference', 'transaction_code');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_type', 'status']);
            $table->dropColumn(['payment_no', 'payment_type', 'payee_type', 'payee_id']);
        });
        Schema::rename('payments', 'petty_cash_disbursements');

        DB::statement("ALTER TABLE petty_cash_disbursements MODIFY payment_method ENUM($legacy) NOT NULL DEFAULT 'cash'");
        DB::statement("ALTER TABLE petty_cash_top_ups MODIFY payment_method ENUM($legacy) NOT NULL DEFAULT 'cash'");

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_name');
            $table->foreignId('payment_source_id')->nullable()
                ->constrained('payment_sources')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $sources = DB::table('payment_sources')->pluck('id', 'code');
        foreach ([
            ['Bank Transfer', 'BANK-MAIN'], ['Cash', 'PC-MAIN'], ['Check', 'BANK-MAIN'],
            ['Credit Card', 'CARD'], ['Mobile Money', 'MPESA'], ['Equity Bank', 'BANK-MAIN'],
            ['NCBA Bank', 'BANK-ALT'], ['Main Petty Cash Float', 'PC-MAIN'],
        ] as [$name, $sourceCode]) {
            DB::table('payment_methods')->insert([
                'method_name' => $name,
                'payment_source_id' => $sources[$sourceCode] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()
                ->constrained('payment_methods')->nullOnDelete();
        });
        DB::statement(
            "UPDATE bill_payments bp JOIN payment_methods pm
             ON pm.method_name = CASE bp.payment_method
                 WHEN 'bank_transfer' THEN 'Bank Transfer' WHEN 'cash' THEN 'Cash'
                 WHEN 'cheque' THEN 'Check' WHEN 'card' THEN 'Credit Card'
                 WHEN 'mpesa' THEN 'Mobile Money' ELSE 'Bank Transfer' END
             SET bp.payment_method_id = pm.id",
        );
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        DB::table('payment_sources')->whereIn('code', ['BANK-STANBIC', 'BANK-KCB', 'BANK-FAMILY'])->delete();
        DB::table('payment_sources')->where('code', 'BANK-MAIN')->update(['name' => 'Bank – Main Account']);
        DB::table('payment_sources')->where('code', 'BANK-ALT')->update(['name' => 'Bank – Secondary']);
    }
};
