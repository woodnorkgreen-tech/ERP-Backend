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
        Schema::create('enquiry_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->constrained('project_enquiries')->onDelete('cascade');
            $table->string('title');
            $table->string('type');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->index(['project_enquiry_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enquiry_tasks');
    }
};
