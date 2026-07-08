<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_disbursement_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disbursement_id')->index();
            $table->unsignedBigInteger('top_up_id')->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('transaction_cost', 14, 2)->default(0.00);
            $table->timestamps();

            $table->foreign('disbursement_id')->references('id')->on('petty_cash_disbursements')->onDelete('cascade');
            $table->foreign('top_up_id')->references('id')->on('petty_cash_top_ups')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_disbursement_allocations');
    }
};
