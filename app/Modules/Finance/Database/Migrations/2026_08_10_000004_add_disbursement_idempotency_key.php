<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->after('planned_cost_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
