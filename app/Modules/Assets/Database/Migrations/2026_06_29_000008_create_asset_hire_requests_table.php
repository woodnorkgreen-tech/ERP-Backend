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
        Schema::create('asset_hire_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // 'hire' = short-term, has an expected return date, flows through Returns Queue.
            // 'assign' = long-term company asset (e.g. a laptop) — held until reassigned, leads only.
            $table->string('request_type', 20)->default('hire');

            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('for_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 20)->default('pending'); // pending|approved|rejected|returned|cancelled

            $table->date('out_date')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->date('actual_return_date')->nullable();
            $table->string('return_condition', 30)->nullable();

            $table->text('purpose')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index('status');
            $table->index('for_user_id');
            $table->index('requested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_hire_requests');
    }
};
