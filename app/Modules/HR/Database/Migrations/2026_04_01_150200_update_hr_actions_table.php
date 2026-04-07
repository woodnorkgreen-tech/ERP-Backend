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
        Schema::table('hr_actions', function (Blueprint $table) {
            $table->foreignId('action_type_id')->nullable()->after('employee_id')->constrained('hr_action_types');
            $table->enum('status', ['executed', 'pending_execution', 'cancelled'])->default('executed')->after('reason');
            $table->string('action_type')->nullable()->change(); // Allow null while we transition
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_actions', function (Blueprint $table) {
            $table->dropForeign(['action_type_id']);
            $table->dropColumn(['action_type_id', 'status']);
            $table->string('action_type')->nullable(false)->change();
        });
    }
};
