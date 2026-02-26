<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_order_handovers')) {
            return;
        }

        Schema::create('work_order_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->string('job_number', 190);
            $table->string('client_name', 190);
            $table->string('project', 190)->nullable();
            $table->text('description')->nullable();
            $table->string('quantity', 50)->nullable();
            $table->string('condition', 120)->nullable();
            $table->string('handed_over_by', 190)->nullable();
            $table->string('received_by', 190)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_handovers');
    }
};
