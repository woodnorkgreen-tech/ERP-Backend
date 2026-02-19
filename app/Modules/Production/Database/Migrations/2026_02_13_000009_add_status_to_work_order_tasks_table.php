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
        Schema::table('work_order_tasks', function (Blueprint $table) {
            $table->enum('status', ['pending', 'in_progress', 'paused', 'completed'])->default('pending')->after('included');
            $table->dateTime('started_at')->nullable()->after('status');
            $table->dateTime('paused_at')->nullable()->after('started_at');
            $table->dateTime('completed_at')->nullable()->after('paused_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_tasks', function (Blueprint $table) {
            $table->dropColumn(['status', 'started_at', 'paused_at', 'completed_at']);
        });
    }
};
