<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 2 of Finance Architecture Redesign:
     * Create payment_allocations table to link payments directly to cost lines.
     * This replaces the indirect link through spend_voucher_allocations.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->cascadeOnDelete()
                ->comment('Payment that settled this cost');

            $table->foreignId('cost_line_id')
                ->constrained('cost_lines')
                ->restrictOnDelete()
                ->comment('Cost line being settled');

            $table->decimal('amount', 15, 2)
                ->comment('Amount allocated to this cost line');

            $table->string('allocation_type')
                ->default('settlement')
                ->comment('settlement|partial|advance - nature of allocation');

            $table->timestamps();

            // Database-level idempotency: a retry cannot allocate the same
            // payment to the same liability twice.
            $table->unique(['payment_id', 'cost_line_id']);
            $table->index('cost_line_id'); // For reverse lookup: which payment settled this cost?
        });

        // Migrate existing spend_voucher_allocations data
        // Link: SpendVoucher → Payment (via petty_cash_disbursement_id) → CostLine (via allocation)
        $migratedCount = DB::statement('
            INSERT INTO payment_allocations (payment_id, cost_line_id, amount, allocation_type, created_at, updated_at)
            SELECT 
                sv.petty_cash_disbursement_id as payment_id,
                sva.cost_line_id,
                sva.amount,
                "settlement" as allocation_type,
                sva.created_at,
                sva.updated_at
            FROM spend_voucher_allocations sva
            INNER JOIN spend_vouchers sv ON sva.spend_voucher_id = sv.id
            WHERE sv.petty_cash_disbursement_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM payment_allocations pa 
                WHERE pa.payment_id = sv.petty_cash_disbursement_id 
                AND pa.cost_line_id = sva.cost_line_id
            )
        ');

        $count = DB::table('payment_allocations')->count();
        echo PHP_EOL . "  ✓ Migrated {$count} allocation(s) from spend_voucher_allocations to payment_allocations" . PHP_EOL;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
