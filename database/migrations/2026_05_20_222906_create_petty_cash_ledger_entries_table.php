<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('petty_cash_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->nullable()->index();
            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_snapshot', 15, 2);
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_ledger_entries');
    }
};
