<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncr_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->cascadeOnDelete();
            $table->foreignId('handover_survey_id')->nullable()->constrained('handover_surveys')->nullOnDelete();
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_department')->nullable();
            $table->string('title');
            $table->enum('category', ['quality', 'delivery', 'communication', 'design', 'installation', 'other']);
            $table->text('description');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_enquiry_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncr_reports');
    }
};
