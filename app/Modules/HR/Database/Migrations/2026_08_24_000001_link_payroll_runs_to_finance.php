<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $parentIds = DB::table('chart_of_accounts')
            ->whereIn('code', ['2000', '7000'])
            ->pluck('id', 'code');

        DB::table('chart_of_accounts')->updateOrInsert(
            ['code' => '2160'],
            [
                'name' => 'Net Payroll Payable', 'category' => 'liability',
                'account_type' => 'balance_sheet', 'normal_balance' => 'credit',
                'parent_id' => $parentIds['2000'] ?? null, 'is_postable' => true,
                'updated_at' => $now, 'created_at' => $now,
            ]
        );
        DB::table('chart_of_accounts')->updateOrInsert(
            ['code' => '7550'],
            [
                'name' => 'Salaries & Wages', 'category' => 'expense',
                'account_type' => 'opex', 'normal_balance' => 'debit',
                'parent_id' => $parentIds['7000'] ?? null, 'is_postable' => true,
                'updated_at' => $now, 'created_at' => $now,
            ]
        );

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignId('accrual_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payment_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('payment_source_id')->nullable()
                ->constrained('payment_sources')->nullOnDelete();
            $table->date('payment_date')->nullable();
            $table->string('payment_reference', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accrual_journal_entry_id');
            $table->dropConstrainedForeignId('payment_journal_entry_id');
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropColumn(['payment_date', 'payment_reference']);
        });
    }
};
