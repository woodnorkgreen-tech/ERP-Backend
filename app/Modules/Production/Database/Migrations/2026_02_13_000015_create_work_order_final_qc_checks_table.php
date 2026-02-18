<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_final_qc_checks')) {
            return;
        }

        Schema::create('work_order_final_qc_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('category', 120);
            $table->string('title', 160);
            $table->string('notes')->nullable();
            $table->enum('status', ['pending', 'passed', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'category', 'title'], 'work_order_final_qc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_final_qc_checks');
    }
};
