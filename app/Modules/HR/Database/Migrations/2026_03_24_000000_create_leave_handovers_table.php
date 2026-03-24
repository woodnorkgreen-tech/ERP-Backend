<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('project_name')->nullable();
            $table->text('task_description')->nullable();
            $table->string('current_status')->nullable();
            $table->text('pending_actions')->nullable();
            $table->foreignId('handed_over_to_employee_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('department')->nullable();
            $table->date('follow_up_deadline')->nullable();
            $table->text('update_during_leave')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_handovers');
    }
};
