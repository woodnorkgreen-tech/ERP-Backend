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
        Schema::create('employee_staging_records', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->index();
            $table->string('batch_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('employee_id')->nullable()->index();
            $table->string('id_number')->nullable();
            $table->foreignId('matched_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('staged_data');
            $table->enum('status', ['staged', 'partially_applied', 'fully_applied', 'discarded'])->default('staged');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_staging_records');
    }
};
