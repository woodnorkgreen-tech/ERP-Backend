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
        Schema::table('notifications', function (Blueprint $table) {
            // Add polymorphic columns for task relationship
            $table->string('notifiable_type')->nullable()->after('data');
            $table->unsignedBigInteger('notifiable_id')->nullable()->after('notifiable_type');
            
            // Add indexes for better query performance
            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_index');
            $table->index(['user_id', 'is_read', 'created_at'], 'notifications_user_read_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_index');
            $table->dropIndex('notifications_user_read_index');
            $table->dropColumn(['notifiable_type', 'notifiable_id']);
        });
    }
};
