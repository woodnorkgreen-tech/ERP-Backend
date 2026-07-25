<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderTask;
use App\Modules\Production\Models\WorkOrderTaskAssignee;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TechnicalLabour;
use App\Modules\Projects\Actions\AutoSyncTaskStateAction;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderTaskController extends Controller
{
    public function index(WorkOrder $workOrder): JsonResponse
    {
        $tasks = WorkOrderTask::with('assignees')
            ->where('work_order_id', $workOrder->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $this->formatTasks($tasks)
        ]);
    }

    public function store(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $validated = $request->validate([
            'workstation' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'quantity' => 'nullable|numeric|min:0',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'included' => 'nullable|boolean',
            'status' => 'nullable|in:pending,in_progress,paused,completed',
            'status_reason' => 'nullable|string',
            'safety_checks' => 'nullable|array',
            'safety_checks.ppe' => 'nullable|boolean',
            'safety_checks.machine' => 'nullable|boolean',
            'safety_checks.color' => 'nullable|boolean',
            'safety_checks.finish' => 'nullable|boolean',
            'assignees' => 'nullable|array',
            'assignees.*.type' => 'required_with:assignees|string|in:employee,technical_labour',
            'assignees.*.id' => 'required_with:assignees|integer'
        ]);

        $task = WorkOrderTask::create([
            'work_order_id' => $workOrder->id,
            'workstation' => $validated['workstation'],
            'title' => $validated['title'],
            'quantity' => $validated['quantity'] ?? 0,
            'priority' => $validated['priority'] ?? 'medium',
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'included' => $validated['included'] ?? true,
            'status' => $validated['status'] ?? 'pending',
            'status_reason' => $validated['status_reason'] ?? null,
            'safety_checks' => $validated['safety_checks'] ?? null,
            'created_by' => auth()->id()
        ]);

        $this->syncAssignees($task, $validated['assignees'] ?? []);

        return response()->json([
            'success' => true,
            'message' => 'Task created',
            'data' => $this->formatTasks(collect([$task->load('assignees')]))->first()
        ], 201);
    }

    public function update(Request $request, WorkOrder $workOrder, WorkOrderTask $task): JsonResponse
    {
        if ($task->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Task does not belong to this work order'
            ], 403);
        }

        $validated = $request->validate([
            'workstation' => 'sometimes|string|max:100',
            'title' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|numeric|min:0',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'due_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'included' => 'sometimes|boolean',
            'status' => 'sometimes|in:pending,in_progress,paused,completed',
            'status_reason' => 'sometimes|nullable|string',
            'safety_checks' => 'sometimes|array',
            'safety_checks.ppe' => 'nullable|boolean',
            'safety_checks.machine' => 'nullable|boolean',
            'safety_checks.color' => 'nullable|boolean',
            'safety_checks.finish' => 'nullable|boolean',
            'assignees' => 'sometimes|array',
            'assignees.*.type' => 'required_with:assignees|string|in:employee,technical_labour',
            'assignees.*.id' => 'required_with:assignees|integer'
        ]);

        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'paused' && empty($validated['status_reason'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status reason is required when pausing a task.'
                ], 422);
            }
            if ($validated['status'] === 'in_progress' && !$task->started_at) {
                $validated['started_at'] = now();
            }
            if ($validated['status'] === 'paused') {
                $validated['paused_at'] = now();
            }
            if ($validated['status'] === 'completed') {
                $validated['completed_at'] = now();
            }
        }

        $task->update($validated);

        // Completing the final work-order item is the real production finish
        // action; synchronize the parent lifecycle task immediately.
        if (array_key_exists('status', $validated) && $validated['status'] === 'completed') {
            $lifecycleTask = EnquiryTask::find($workOrder->enquiry_task_id);
            if ($lifecycleTask) {
                app(AutoSyncTaskStateAction::class)->execute($lifecycleTask);
            }
        }

        if (array_key_exists('assignees', $validated)) {
            $this->syncAssignees($task, $validated['assignees'] ?? []);
        }

        return response()->json([
            'success' => true,
            'message' => 'Task updated',
            'data' => $this->formatTasks(collect([$task->load('assignees')]))->first()
        ]);
    }

    public function destroy(WorkOrder $workOrder, WorkOrderTask $task): JsonResponse
    {
        if ($task->work_order_id !== $workOrder->id) {
            return response()->json([
                'success' => false,
                'message' => 'Task does not belong to this work order'
            ], 403);
        }

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted'
        ]);
    }

    private function syncAssignees(WorkOrderTask $task, array $assignees): void
    {
        WorkOrderTaskAssignee::where('work_order_task_id', $task->id)->delete();

        foreach ($assignees as $assignee) {
            WorkOrderTaskAssignee::create([
                'work_order_task_id' => $task->id,
                'assignee_type' => $assignee['type'],
                'assignee_id' => $assignee['id']
            ]);
        }
    }

    private function formatTasks($tasks)
    {
        $employeeIds = [];
        $labourIds = [];

        foreach ($tasks as $task) {
            foreach ($task->assignees as $assignee) {
                if ($assignee->assignee_type === 'employee') {
                    $employeeIds[] = $assignee->assignee_id;
                } elseif ($assignee->assignee_type === 'technical_labour') {
                    $labourIds[] = $assignee->assignee_id;
                }
            }
        }

        $employees = Employee::whereIn('id', array_unique($employeeIds))->get()->keyBy('id');
        $labours = TechnicalLabour::whereIn('id', array_unique($labourIds))->get()->keyBy('id');

        return $tasks->map(function ($task) use ($employees, $labours) {
            $assignees = $task->assignees->map(function ($assignee) use ($employees, $labours) {
                if ($assignee->assignee_type === 'employee') {
                    $person = $employees->get($assignee->assignee_id);
                    return [
                        'id' => $assignee->assignee_id,
                        'type' => 'employee',
                        'name' => $person?->name ?? 'Unknown',
                        'label' => $person ? $person->name . ' (Employee)' : 'Unknown'
                    ];
                }

                $person = $labours->get($assignee->assignee_id);
                return [
                    'id' => $assignee->assignee_id,
                    'type' => 'technical_labour',
                    'name' => $person?->full_name ?? 'Unknown',
                    'label' => $person ? $person->full_name . ' (Technician)' : 'Unknown'
                ];
            });

            return [
                'id' => $task->id,
                'workstation' => $task->workstation,
                'title' => $task->title,
                'quantity' => $task->quantity,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'notes' => $task->notes,
                'included' => $task->included,
                'status' => $task->status,
                'status_reason' => $task->status_reason,
                'safety_checks' => $task->safety_checks ?? null,
                'started_at' => $task->started_at?->toISOString(),
                'paused_at' => $task->paused_at?->toISOString(),
                'completed_at' => $task->completed_at?->toISOString(),
                'assignees' => $assignees
            ];
        });
    }
}
