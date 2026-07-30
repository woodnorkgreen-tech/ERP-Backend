<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('setdown_tasks')->select(['id', 'issues', 'created_by'])->whereNotNull('issues')->orderBy('id')->chunkById(100, function ($tasks) {
            foreach ($tasks as $task) {
                if (!$task->created_by) continue;
                foreach ((array) json_decode($task->issues, true) as $issue) {
                    $title = trim((string) ($issue['title'] ?? ''));
                    if ($title === '') continue;

                    $exists = DB::table('setdown_task_issues')
                        ->where('setdown_task_id', $task->id)
                        ->where('title', $title)
                        ->exists();
                    if ($exists) continue;

                    DB::table('setdown_task_issues')->insert([
                        'setdown_task_id' => $task->id,
                        'title' => $title,
                        'description' => $issue['description'] ?? $title,
                        'category' => $issue['category'] ?? 'other',
                        'priority' => $issue['priority'] ?? 'medium',
                        'status' => $issue['status'] ?? 'open',
                        'reported_by' => is_numeric($issue['reported_by'] ?? null) ? (int) $issue['reported_by'] : $task->created_by,
                        'assigned_to' => is_numeric($issue['assigned_to'] ?? null) ? (int) $issue['assigned_to'] : null,
                        'resolved_at' => $issue['resolved_at'] ?? null,
                        'resolution' => $issue['resolution'] ?? null,
                        'created_at' => $issue['reported_at'] ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Relational records are retained because deleting operational history is unsafe.
    }
};
