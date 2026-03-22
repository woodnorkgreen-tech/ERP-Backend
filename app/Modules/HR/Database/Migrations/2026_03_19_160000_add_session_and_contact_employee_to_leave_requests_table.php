<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('session', 32)->default('full_day')->after('days_requested');
            $table->foreignId('contact_employee_id')->nullable()->after('employee_id')->constrained('employees')->nullOnDelete();
            $table->decimal('days_requested', 4, 1)->change();

            $table->index(['contact_employee_id']);
            $table->index(['session']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['contact_employee_id']);
            $table->dropIndex(['session']);
            $table->dropForeign(['contact_employee_id']);
            $table->dropColumn(['contact_employee_id', 'session']);
            $table->unsignedSmallInteger('days_requested')->change();
        });
    }
};
