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
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('department_id')->constrained('employees')->onDelete('set null');
            $table->string('payee_name')->nullable()->after('payee_id'); // For non-employee payees or ad-hoc names
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropForeign(['payee_id']);
            $table->dropColumn(['payee_id', 'payee_name']);
        });
    }
};
