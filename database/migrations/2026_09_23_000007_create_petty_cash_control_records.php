<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('held_by')->nullable()->after('current_balance')->index();
        });

        Schema::create('petty_cash_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_balance_id')->default(1)->constrained('petty_cash_balances')->restrictOnDelete();
            $table->decimal('system_balance', 14, 2);
            $table->decimal('physical_cash', 14, 2);
            $table->decimal('outstanding_advances', 14, 2)->default(0);
            $table->decimal('supported_adjustments', 14, 2)->default(0);
            $table->decimal('variance', 14, 2);
            $table->unsignedBigInteger('counted_by');
            $table->unsignedBigInteger('custodian_user_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('explanation')->nullable();
            $table->string('corrective_action_reference')->nullable();
            // W5-7: what the expected float is made of at the moment of the count,
            // so a variance can be explained rather than just stated.
            $table->json('components')->nullable();
            $table->timestamps();
        });

        Schema::create('petty_cash_custody_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_balance_id')->default(1)->constrained('petty_cash_balances')->restrictOnDelete();
            $table->unsignedBigInteger('outgoing_custodian_user_id')->nullable();
            $table->unsignedBigInteger('incoming_custodian_user_id');
            $table->decimal('system_balance', 14, 2);
            $table->decimal('physical_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_custody_handovers');
        Schema::dropIfExists('petty_cash_cash_counts');
        Schema::table('petty_cash_balances', fn (Blueprint $table) => $table->dropColumn('held_by'));
    }
};
