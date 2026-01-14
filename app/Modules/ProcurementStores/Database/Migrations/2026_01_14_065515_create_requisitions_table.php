<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_number')->unique();
            $table->date('date');
            $table->enum('requested_by_type', ['project', 'office', 'employee'])->default('office');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->enum('urgency', ['normal', 'urgent'])->default('normal');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'completed'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requisition_id');
            $table->unsignedBigInteger('material_id');
            $table->integer('quantity');
            $table->text('purpose');
            $table->text('reason')->nullable();
            $table->timestamps();
            
            $table->foreign('requisition_id')->references('id')->on('requisitions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};