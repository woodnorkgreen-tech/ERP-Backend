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
        Schema::create('hr_job_postings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('department')->nullable();
            $table->string('employment_type')->nullable(); // Full-time, Part-time, Contract, etc.
            $table->string('location')->nullable();
            $table->longText('description')->nullable();
            $table->longText('requirements')->nullable();
            $table->enum('status', ['Draft', 'Published', 'Closed', 'On Hold'])->default('Draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_job_postings');
    }
};
