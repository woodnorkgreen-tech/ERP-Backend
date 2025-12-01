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
        Schema::dropIfExists('teams_activity_logs');

        Schema::create('teams_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teams_task_id')->nullable()->constrained('teams_tasks')->onDelete('set null');
            $table->unsignedBigInteger('teams_member_id')->nullable(); 
            
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            
            $table->foreignId('performed_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams_activity_logs');
    }
};
