<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Introduces the three-way match on supplier invoices.
 *
 * Bills that already exist were raised before the control existed, and several
 * of them predate goods receipts being recorded at all. Holding them to a match
 * they were never able to pass would freeze every open supplier balance on the
 * day this ships, so they are stamped 'legacy': verified as at the changeover,
 * visibly on a different basis, and never silently presented as having passed a
 * check they did not. Everything raised from here on answers to the match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->string('supplier_invoice_number', 120)->nullable()->after('notes');
            $table->foreignId('verified_by')->nullable()->after('supplier_invoice_number')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_basis', 24)->nullable()->after('verified_at');
            $table->string('verification_fingerprint', 64)->nullable()->after('verification_basis');
            $table->text('verification_notes')->nullable()->after('verification_fingerprint');
            $table->index(['verified_at', 'status'], 'bills_verification_idx');
        });

        DB::table('bills')->whereNull('verified_at')->update([
            'verified_at' => DB::raw('COALESCE(created_at, NOW())'),
            'verification_basis' => 'legacy',
            'verification_notes' => 'Raised before the purchase-order / receipt / invoice match was introduced on 2026-09-05. Not matched against a goods receipt.',
        ]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_verification_idx');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn([
                'supplier_invoice_number',
                'verified_at',
                'verification_basis',
                'verification_fingerprint',
                'verification_notes',
            ]);
        });
    }
};
