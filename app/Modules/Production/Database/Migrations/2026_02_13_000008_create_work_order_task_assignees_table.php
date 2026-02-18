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
        Schema::create('work_order_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_task_id');
            $table->string('assignee_type');
            $table->unsignedBigInteger('assignee_id');
            $table->timestamps();

            $table->foreign('work_order_task_id')->references('id')->on('work_order_tasks')->onDelete('cascade');
            $table->index(['assignee_type', 'assignee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_task_assignees');
    }
};
