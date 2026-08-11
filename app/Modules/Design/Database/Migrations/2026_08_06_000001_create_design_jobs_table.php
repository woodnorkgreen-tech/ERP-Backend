<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('job_number')->nullable();
            $table->string('title');
            $table->enum('source_type', ['project_scope', 'manual', 'revision', 'internal_concept'])->default('manual');
            $table->enum('status', [
                'pending',
                'in_design',
                'submitted',
                'approved',
                'not_approved',
                'cancelled',
                'partially_handed_off',
                'handed_off',
            ])->default('pending');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_enquiry_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['priority', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_jobs');
    }
};
