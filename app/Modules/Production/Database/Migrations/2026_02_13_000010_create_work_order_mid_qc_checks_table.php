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
        Schema::create('work_order_mid_qc_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('workstation');
            $table->string('category');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'passed', 'failed'])->default('pending');
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->onDelete('cascade');
            $table->foreign('checked_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['work_order_id', 'workstation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_mid_qc_checks');
    }
};
