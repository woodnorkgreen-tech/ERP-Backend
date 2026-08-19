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
                        'resolved_at' => $this->parseDate($issue['resolved_at'] ?? null),
                        'resolution' => $issue['resolution'] ?? null,
                        'created_at' => $this->parseDate($issue['reported_at'] ?? null) ?? now(),
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

    /**
     * Historic issue timestamps were stored as JSON and came out of Carbon's
     * default JSON serialization: ISO 8601 with microseconds and a 'Z' suffix
     * (e.g. 2026-02-02T09:10:38.718755Z). MariaDB's DATETIME columns reject
     * that format outright (strict mode), so every row needs re-parsing into
     * 'Y-m-d H:i:s' before insert. Malformed/missing values fall back to null
     * so the insert never fails on bad historic data.
     */
    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
};
