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
     * Phase 1 of Finance Architecture Redesign:
     * Add explicit flag to control which payment sources can make payments.
     * This replaces scattered UI filtering with a single configuration point.
     */
    public function up(): void
    {
        Schema::table('payment_sources', function (Blueprint $table) {
            $table->boolean('can_make_payment')
                ->default(true)
                ->after('type')
                ->comment('Whether this source can be used for outgoing payments');
        });

        // Set existing payable-type sources to false (payables are liabilities, never cash sources)
        DB::table('payment_sources')
            ->where('type', 'payable')
            ->update(['can_make_payment' => false]);

        // Log the configuration change
        $payablesCount = DB::table('payment_sources')
            ->where('type', 'payable')
            ->where('can_make_payment', false)
            ->count();

        if ($payablesCount > 0) {
            echo PHP_EOL . "  ✓ Configured {$payablesCount} payable-type source(s) as non-payment-capable" . PHP_EOL;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_sources', function (Blueprint $table) {
            $table->dropColumn('can_make_payment');
        });
    }
};
