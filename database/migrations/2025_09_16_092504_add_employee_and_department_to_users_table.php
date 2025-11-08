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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('email')->constrained()->onDelete('set null');
            $table->foreignId('department_id')->nullable()->after('employee_id')->constrained('departments')->onDelete('set null');
            $table->boolean('is_active')->default(true)->after('department_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index(['employee_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'department_id']);
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn(['employee_id', 'department_id', 'is_active', 'last_login_at']);
        });
    }
};
