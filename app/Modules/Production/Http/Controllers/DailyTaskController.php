<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\JobCard;
use App\Modules\Production\Models\DailyTask;
use App\Modules\Production\Models\DailyIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyTaskController extends Controller
{
    /**
     * Add a task to a job card.
     */
    public function store(Request $request, JobCard $jobCard): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string',
            'work_order_id' => 'nullable|exists:work_orders,id',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Calculate hours worked
            $startTime = \Carbon\Carbon::createFromTimeString($validated['start_time']);
            $endTime = \Carbon\Carbon::createFromTimeString($validated['end_time']);
            
            // Handle overnight tasks
            if ($endTime->lt($startTime)) {
                $endTime->addDay();
            }
            
            $hoursWorked = $startTime->diffInMinutes($endTime) / 60;

            $task = $jobCard->tasks()->create([
                'description' => $validated['description'],
                'work_order_id' => $validated['work_order_id'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'hours_worked' => $hoursWorked,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task added successfully',
                'data' => $task->load('workOrder')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a task.
     */
    public function update(Request $request, DailyTask $task): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'sometimes|string',
            'work_order_id' => 'sometimes|nullable|exists:work_orders,id',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'notes' => 'sometimes|nullable|string',
        ]);

        try {
            // Recalculate hours if times changed
            if (isset($validated['start_time']) || isset($validated['end_time'])) {
                $startTime = \Carbon\Carbon::createFromTimeString(
                    $validated['start_time'] ?? $task->start_time
                );
                $endTime = \Carbon\Carbon::createFromTimeString(
                    $validated['end_time'] ?? $task->end_time
                );
                
                if ($endTime->lt($startTime)) {
                    $endTime->addDay();
                }
                
                $validated['hours_worked'] = $startTime->diffInMinutes($endTime) / 60;
            }

            $task->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => $task->load('workOrder')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a task.
     */
    public function destroy(DailyTask $task): JsonResponse
    {
        try {
            $task->delete();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add an issue to a job card.
     */
    public function storeIssue(Request $request, JobCard $jobCard): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string',
            'resolution' => 'nullable|string',
            'status' => 'sometimes|in:open,resolved',
        ]);

        try {
            $issue = $jobCard->issues()->create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Issue added successfully',
                'data' => $issue
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add issue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an issue.
     */
    public function updateIssue(Request $request, DailyIssue $issue): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'sometimes|string',
            'resolution' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:open,resolved',
        ]);

        try {
            // Set resolved_at if status is being changed to resolved
            if (isset($validated['status']) && $validated['status'] === 'resolved') {
                $validated['resolved_at'] = now();
            }

            $issue->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Issue updated successfully',
                'data' => $issue
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update issue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an issue.
     */
    public function destroyIssue(DailyIssue $issue): JsonResponse
    {
        try {
            $issue->delete();

            return response()->json([
                'success' => true,
                'message' => 'Issue deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete issue: ' . $e->getMessage()
            ], 500);
        }
    }
}
