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
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained('workflow_templates')->onDelete('cascade');
            $table->enum('entity_type', ['enquiry', 'project']);
            $table->unsignedBigInteger('entity_id');
            $table->enum('status', ['active', 'completed', 'paused', 'cancelled'])->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
