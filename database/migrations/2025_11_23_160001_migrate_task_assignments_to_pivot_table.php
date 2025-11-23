<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate existing assignments from assigned_to column to pivot table
        DB::table('enquiry_tasks')
            ->whereNotNull('assigned_to')
            ->get()
            ->each(function ($task) {
                DB::table('enquiry_task_user')->insert([
                    'enquiry_task_id' => $task->id,
                    'user_id' => $task->assigned_to,
                    'assigned_by' => $task->assigned_by,
                    'assigned_at' => $task->assigned_at ?? $task->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // Migrate existing assignments from assigned_user_id column (if any)
        // Only if they don't already exist from assigned_to migration
        DB::table('enquiry_tasks')
            ->whereNotNull('assigned_user_id')
            ->whereNull('assigned_to')
            ->get()
            ->each(function ($task) {
                DB::table('enquiry_task_user')->insertOrIgnore([
                    'enquiry_task_id' => $task->id,
                    'user_id' => $task->assigned_user_id,
                    'assigned_by' => $task->assigned_by,
                    'assigned_at' => $task->assigned_at ?? $task->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optionally restore assignments back to assigned_to column
        DB::table('enquiry_task_user')
            ->get()
            ->each(function ($assignment) {
                // Only restore if task doesn't already have an assignment
                DB::table('enquiry_tasks')
                    ->where('id', $assignment->enquiry_task_id)
                    ->whereNull('assigned_to')
                    ->update([
                        'assigned_to' => $assignment->user_id,
                        'assigned_by' => $assignment->assigned_by,
                        'assigned_at' => $assignment->assigned_at,
                    ]);
            });
    }
};
