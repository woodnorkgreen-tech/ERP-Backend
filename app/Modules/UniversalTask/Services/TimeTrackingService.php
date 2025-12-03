<?php

namespace App\Modules\UniversalTask\Services;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskTimeEntry;
use App\Modules\UniversalTask\Services\TaskPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class TimeTrackingService
{
    protected TaskPermissionService $permissionService;

    public function __construct(TaskPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Log time for a task.
     *
     * @param Task $task The task to log time for
     * @param array $timeData Time entry data
     * @param int $userId User logging the time
     * @return TaskTimeEntry The created time entry
     */
    public function logTime(Task $task, array $timeData, int $userId): TaskTimeEntry
    {
        // Validate time data
        $validatedData = $this->validateTimeData($timeData);

        DB::beginTransaction();

        try {
            // Create time entry
            $timeEntry = TaskTimeEntry::create([
                'task_id' => $task->id,
                'user_id' => $userId,
                'hours' => $validatedData['hours'],
                'date_worked' => $validatedData['date_worked'],
                'description' => $validatedData['description'] ?? null,
                'started_at' => $validatedData['started_at'] ?? null,
                'ended_at' => $validatedData['ended_at'] ?? null,
                'is_billable' => $validatedData['is_billable'] ?? true,
                'metadata' => $validatedData['metadata'] ?? null,
            ]);

            // Update task's actual hours
            $task->updateActualHours();

            DB::commit();

            Log::info('Time logged successfully', [
                'task_id' => $task->id,
                'time_entry_id' => $timeEntry->id,
                'hours' => $timeEntry->hours,
                'user_id' => $userId,
            ]);

            return $timeEntry;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Time logging failed', [
                'task_id' => $task->id,
                'time_data' => $timeData,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update a time entry.
     *
     * @param TaskTimeEntry $timeEntry The time entry to update
     * @param array $timeData Updated time data
     * @param int $userId User making the update
     * @return TaskTimeEntry The updated time entry
     */
    public function updateTimeEntry(TaskTimeEntry $timeEntry, array $timeData, int $userId): TaskTimeEntry
    {
        // Check if user can edit this time entry (only the creator can edit)
        if ($timeEntry->user_id !== $userId) {
            throw new \InvalidArgumentException('You can only edit your own time entries.');
        }

        // Validate time data
        $validatedData = $this->validateTimeData($timeData);

        DB::beginTransaction();

        try {
            // Update time entry
            $timeEntry->update($validatedData);

            // Update task's actual hours
            $timeEntry->task->updateActualHours();

            DB::commit();

            Log::info('Time entry updated successfully', [
                'time_entry_id' => $timeEntry->id,
                'task_id' => $timeEntry->task_id,
                'hours' => $timeEntry->hours,
                'user_id' => $userId,
            ]);

            return $timeEntry;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Time entry update failed', [
                'time_entry_id' => $timeEntry->id,
                'time_data' => $timeData,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a time entry.
     *
     * @param TaskTimeEntry $timeEntry The time entry to delete
     * @param int $userId User making the deletion
     * @return bool True if deleted successfully
     */
    public function deleteTimeEntry(TaskTimeEntry $timeEntry, int $userId): bool
    {
        // Check if user can delete this time entry (only the creator can delete)
        if ($timeEntry->user_id !== $userId) {
            throw new \InvalidArgumentException('You can only delete your own time entries.');
        }

        DB::beginTransaction();

        try {
            $task = $timeEntry->task;
            $timeEntry->delete();

            // Update task's actual hours
            $task->updateActualHours();

            DB::commit();

            Log::info('Time entry deleted successfully', [
                'time_entry_id' => $timeEntry->id,
                'task_id' => $task->id,
                'user_id' => $userId,
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Time entry deletion failed', [
                'time_entry_id' => $timeEntry->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get time entries for a task.
     *
     * @param Task $task The task
     * @param array $filters Optional filters
     * @return Collection Collection of time entries
     */
    public function getTimeEntriesForTask(Task $task, array $filters = []): Collection
    {
        $query = $task->timeEntries()->with('user');

        // Apply filters
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('date_worked', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('date_worked', '<=', $filters['date_to']);
        }

        if (isset($filters['is_billable'])) {
            $query->where('is_billable', $filters['is_billable']);
        }

        return $query->orderBy('date_worked', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    /**
     * Calculate time variance for a task.
     *
     * @param Task $task The task
     * @return float|null Time variance (actual - estimated)
     */
    public function calculateTimeVariance(Task $task): ?float
    {
        return $task->calculateTimeVariance();
    }

    /**
     * Get time aggregation for a user within a date range.
     *
     * @param int $userId User ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param array $filters Optional filters
     * @return array Time aggregation data
     */
    public function getUserTimeAggregation(int $userId, string $startDate, string $endDate, array $filters = []): array
    {
        $query = TaskTimeEntry::where('user_id', $userId)
            ->whereBetween('date_worked', [$startDate, $endDate]);

        // Apply filters
        if (isset($filters['task_id'])) {
            $query->where('task_id', $filters['task_id']);
        }

        if (isset($filters['is_billable'])) {
            $query->where('is_billable', $filters['is_billable']);
        }

        $entries = $query->get();

        $totalHours = $entries->sum('hours');
        $billableHours = $entries->where('is_billable', true)->sum('hours');
        $nonBillableHours = $entries->where('is_billable', false)->sum('hours');

        // Group by task
        $byTask = $entries->groupBy('task_id')->map(function ($taskEntries) {
            return [
                'task_id' => $taskEntries->first()->task_id,
                'task_title' => $taskEntries->first()->task->title ?? 'Unknown Task',
                'total_hours' => $taskEntries->sum('hours'),
                'entries_count' => $taskEntries->count(),
            ];
        });

        // Group by date
        $byDate = $entries->groupBy('date_worked')->map(function ($dateEntries) {
            return [
                'date' => $dateEntries->first()->date_worked->format('Y-m-d'),
                'total_hours' => $dateEntries->sum('hours'),
                'entries_count' => $dateEntries->count(),
            ];
        });

        return [
            'user_id' => $userId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_hours' => round($totalHours, 2),
                'billable_hours' => round($billableHours, 2),
                'non_billable_hours' => round($nonBillableHours, 2),
                'total_entries' => $entries->count(),
            ],
            'by_task' => $byTask->values(),
            'by_date' => $byDate->values(),
        ];
    }

    /**
     * Get time aggregation for a department within a date range.
     *
     * @param int $departmentId Department ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param array $filters Optional filters
     * @return array Time aggregation data
     */
    public function getDepartmentTimeAggregation(int $departmentId, string $startDate, string $endDate, array $filters = []): array
    {
        $query = TaskTimeEntry::join('tasks', 'task_time_entries.task_id', '=', 'tasks.id')
            ->where('tasks.department_id', $departmentId)
            ->whereBetween('task_time_entries.date_worked', [$startDate, $endDate])
            ->select('task_time_entries.*');

        // Apply filters
        if (isset($filters['user_id'])) {
            $query->where('task_time_entries.user_id', $filters['user_id']);
        }

        if (isset($filters['is_billable'])) {
            $query->where('task_time_entries.is_billable', $filters['is_billable']);
        }

        $entries = $query->with(['user', 'task'])->get();

        $totalHours = $entries->sum('hours');
        $billableHours = $entries->where('is_billable', true)->sum('hours');

        // Group by user
        $byUser = $entries->groupBy('user_id')->map(function ($userEntries) {
            return [
                'user_id' => $userEntries->first()->user_id,
                'user_name' => $userEntries->first()->user->name ?? 'Unknown User',
                'total_hours' => $userEntries->sum('hours'),
                'entries_count' => $userEntries->count(),
            ];
        });

        return [
            'department_id' => $departmentId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => [
                'total_hours' => round($totalHours, 2),
                'billable_hours' => round($billableHours, 2),
                'total_entries' => $entries->count(),
                'unique_users' => $byUser->count(),
            ],
            'by_user' => $byUser->values(),
        ];
    }

    /**
     * Validate time entry data.
     *
     * @param array $data Time entry data
     * @return array Validated data
     */
    protected function validateTimeData(array $data): array
    {
        $rules = [
            'hours' => 'required|numeric|min:0.01|max:24',
            'date_worked' => 'required|date|before_or_equal:today',
            'description' => 'nullable|string|max:1000',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|after:started_at',
            'is_billable' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];

        return validator($data, $rules)->validate();
    }
}