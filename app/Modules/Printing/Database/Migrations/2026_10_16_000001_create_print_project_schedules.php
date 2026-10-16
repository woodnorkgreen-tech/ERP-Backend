<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_project_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_job_id')->unique()->constrained('design_jobs')->cascadeOnDelete();
            $table->date('printing_date')->nullable()->index();
            $table->foreignId('machine_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_project_schedules');
    }
};
