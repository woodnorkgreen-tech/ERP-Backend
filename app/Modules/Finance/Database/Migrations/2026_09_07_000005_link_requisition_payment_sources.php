<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->unsignedBigInteger('top_up_id')->nullable()->change();
        });
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('payment_source_id')->nullable()->constrained('payment_sources')->restrictOnDelete();
            $table->foreignId('disbursement_id')->nullable()->unique()->constrained('petty_cash_disbursements')->restrictOnDelete();
        });
        // Preserve the known source of existing payments without inventing cash movements.
        DB::statement('UPDATE bill_payments bp JOIN payment_methods pm ON pm.id = bp.payment_method_id SET bp.payment_source_id = pm.payment_source_id');
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursement_id');
            $table->dropConstrainedForeignId('payment_source_id');
        });
        // Non-cash payments have no top-up, so retain nullability on rollback.
    }
};
