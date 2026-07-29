<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')->where('status', 'blocked')->update(['status' => 'cancelled']);
        DB::table('tasks')->where('status', 'overdue')->update(['status' => 'pending']);

        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'review', 'completed', 'cancelled') DEFAULT 'pending'");

        if (Schema::hasColumn('tasks', 'blocked_reason')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('blocked_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('blocked_reason')->nullable()->after('completed_at');
        });

        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'blocked', 'review', 'completed', 'cancelled', 'overdue') DEFAULT 'pending'");
    }
};
