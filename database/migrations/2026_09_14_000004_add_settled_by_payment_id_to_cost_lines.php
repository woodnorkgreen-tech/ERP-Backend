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
     * Phase 3 of Finance Architecture Redesign:
     * Add direct link from cost line to the payment that settled it.
     * Makes reconciliation queries simple: "Show me unsettled liabilities" = whereNull('settled_by_payment_id').
     */
    public function up(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->foreignId('settled_by_payment_id')
                ->nullable()
                ->after('funding_voucher_id')
                ->comment('Direct link to payment that settled this liability')
                ->constrained('payments')
                ->nullOnDelete();
        });

        // Populate from existing payment_allocations (created in Phase 2)
        // Link: CostLine ← PaymentAllocation → Payment
        $populated = DB::statement('
            UPDATE cost_lines cl
            INNER JOIN payment_allocations pa ON pa.cost_line_id = cl.id
            INNER JOIN payments p ON pa.payment_id = p.id
            SET cl.settled_by_payment_id = p.id
            WHERE cl.settled_by_payment_id IS NULL
            AND p.status = "paid"
        ');

        $count = DB::table('cost_lines')->whereNotNull('settled_by_payment_id')->count();
        echo PHP_EOL . "  ✓ Populated settled_by_payment_id for {$count} cost line(s)" . PHP_EOL;

        // Also try to populate from funding_voucher_id for older records
        // that may not have payment_allocations yet
        DB::statement('
            UPDATE cost_lines cl
            INNER JOIN spend_vouchers sv ON sv.id = cl.funding_voucher_id
            INNER JOIN payments p ON p.voucher_id = sv.id
            SET cl.settled_by_payment_id = p.id
            WHERE cl.settled_by_payment_id IS NULL
            AND cl.funding_voucher_id IS NOT NULL
            AND p.status = "paid"
        ');

        $additionalCount = DB::table('cost_lines')
            ->whereNotNull('settled_by_payment_id')
            ->count() - $count;

        if ($additionalCount > 0) {
            echo PHP_EOL . "  ✓ Populated {$additionalCount} additional cost line(s) from funding_voucher_id" . PHP_EOL;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->dropForeign(['settled_by_payment_id']);
            $table->dropColumn('settled_by_payment_id');
        });
    }
};
