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
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('status');
            $table->dateTime('due_date')->nullable()->after('priority');
            $table->timestamp('assigned_at')->nullable()->after('due_date');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null')->after('assigned_at');
            $table->text('notes')->nullable()->after('assigned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            $table->dropForeign(['assigned_by']);
            $table->dropColumn(['priority', 'due_date', 'assigned_at', 'assigned_by', 'notes']);
        });
    }
};
