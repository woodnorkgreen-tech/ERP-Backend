<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_clearances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id');
            $table->string('clearance_key');
            $table->string('label');
            $table->string('department_tag')->nullable();
            $table->enum('status', ['pending', 'cleared', 'flagged'])->default('pending');
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->text('flag_reason')->nullable();
            $table->boolean('is_applicable')->default(true);
            $table->boolean('is_needed')->default(true);
            $table->timestamps();

            $table->index('offboarding_case_id');
            $table->index(['offboarding_case_id', 'clearance_key'], 'ofb_clearances_case_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_clearances');
    }
};
