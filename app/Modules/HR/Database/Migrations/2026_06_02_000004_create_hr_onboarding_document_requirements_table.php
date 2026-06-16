<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->string('document_key');
            $table->string('label');
            $table->boolean('is_required')->default(true);
            $table->enum('status', ['pending', 'submitted', 'verified', 'rejected'])->default('pending');
            $table->unsignedBigInteger('employee_document_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('onboarding_case_id');
            $table->index(['onboarding_case_id', 'document_key'], 'ob_doc_req_case_doc_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_document_requirements');
    }
};
