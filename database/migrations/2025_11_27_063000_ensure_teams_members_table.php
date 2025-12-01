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
        if (!Schema::hasTable('teams_members')) {
            Schema::create('teams_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teams_task_id')->constrained('teams_tasks')->onDelete('cascade');
                $table->string('member_name');
                $table->string('member_email')->nullable();
                $table->string('member_phone')->nullable();
                $table->string('member_role')->nullable();
                $table->decimal('hourly_rate', 8, 2)->nullable();
                $table->boolean('is_lead')->default(false);
                $table->boolean('is_active')->default(true);
                
                $table->timestamp('assigned_at')->useCurrent();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
                
                $table->timestamp('unassigned_at')->nullable();
                $table->foreignId('unassigned_by')->nullable()->constrained('users')->onDelete('set null');
                
                $table->decimal('efficiency_rating', 3, 2)->nullable();
                $table->text('performance_notes')->nullable();
                
                $table->timestamps();
            });
        } else {
            // If table exists, ensure FK has cascade delete
            Schema::table('teams_members', function (Blueprint $table) {
                // Drop existing FK if possible (might need exact name)
                // Instead, let's just assume if it exists it might be wrong, but modifying FKs is tricky without knowing the name.
                // However, we can try to drop the constraint by array syntax which guesses the name
                try {
                    $table->dropForeign(['teams_task_id']);
                } catch (\Exception $e) {
                    // Ignore if it doesn't exist
                }
                
                // Re-add with cascade
                $table->foreign('teams_task_id')
                      ->references('id')
                      ->on('teams_tasks')
                      ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams_members');
    }
};
