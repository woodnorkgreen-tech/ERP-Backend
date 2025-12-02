<?php

namespace App\Modules\logisticsTask\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\logisticsTask\Services\LogisticsTaskService;
use App\Modules\logisticsTask\Services\TransportItemService;
use App\Modules\logisticsTask\Services\LogisticsChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogisticsTaskController extends Controller
{
    public function __construct(
        private LogisticsTaskService $logisticsService,
        private TransportItemService $transportItemService,
        private LogisticsChecklistService $checklistService
    ) {}

    /**
     * Get logistics data for a task
     */
    public function show(int $taskId): JsonResponse
    {
        try {
            $data = $this->logisticsService->getLogisticsForTask($taskId);

            if ($data === null) {
                return response()->json([
                    'message' => 'No logistics data found for this task',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'message' => 'Logistics data retrieved successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve logistics data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available drivers from HR system (logistics department only)
     */
    public function getDrivers(): JsonResponse
    {
        try {
            // Get logistics department
            $logisticsDepartment = \App\Modules\HR\Models\Department::where('name', 'Logistics')->first();

            $query = \App\Modules\HR\Models\Employee::active()
                ->with('department')
                ->select(['id', 'first_name', 'last_name', 'phone', 'department_id', 'position']);

            // Filter by logistics department
            if ($logisticsDepartment) {
                $query->where('department_id', $logisticsDepartment->id);
            } else {
                // Fallback: filter by department name if exact match fails
                $query->whereHas('department', function ($q) {
                    $q->where('name', 'like', '%logistics%');
                });
            }

            $drivers = $query->get()
                ->map(function ($employee) {
                    return [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'phone' => $employee->phone,
                        'position' => $employee->position,
                        'department' => $employee->department ? $employee->department->name : null,
                        'label' => $employee->name . ' (' . $employee->phone . ')' . ($employee->position ? ' - ' . $employee->position : ''),
                        'department_id' => $employee->department_id
                    ];
                });

            return response()->json([
                'message' => 'Drivers retrieved successfully',
                'data' => $drivers
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve drivers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save logistics planning data
     */
    public function savePlanning(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'vehicle_type' => 'nullable|string|max:100',
                'vehicle_identification' => 'nullable|string|max:100',
                'driver_name' => 'nullable|string|max:100',
                'driver_contact' => 'nullable|string|max:20',
                'route.origin' => 'nullable|string|max:255',
                'route.destination' => 'nullable|string|max:255',
                'route.distance' => 'nullable|numeric|min:0',
                'route.travel_time' => 'nullable|string|max:50',
                'timeline.departure_time' => 'nullable|string|max:20',
                'timeline.arrival_time' => 'nullable|string|max:20',
                'timeline.setup_start_time' => 'nullable|string|max:20',
            ]);

            $logisticsTask = $this->logisticsService->saveLogisticsPlanning($taskId, $validated);

            return response()->json([
                'message' => 'Logistics planning saved successfully',
                'data' => $logisticsTask
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save logistics planning',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update team confirmation
     */
    public function updateTeamConfirmation(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'setup_teams_confirmed' => 'required|boolean',
                'notes' => 'nullable|string|max:1000',
            ]);

            $logisticsTask = $this->logisticsService->updateTeamConfirmation($taskId, $validated);

            return response()->json([
                'message' => 'Team confirmation updated successfully',
                'data' => $logisticsTask
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update team confirmation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign a team to a logistics task
     */
    public function assignTeam(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'team_task_id' => 'required|integer|exists:teams_tasks,id',
            ]);

            $logisticsTask = $this->logisticsService->assignTeam($taskId, $validated['team_task_id']);

            return response()->json([
                'message' => 'Team assigned successfully',
                'data' => $logisticsTask
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign team',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get transport items for a task
     */
    public function getTransportItems(int $taskId): JsonResponse
    {
        try {
            $items = $this->logisticsService->getTransportItems($taskId);

            return response()->json([
                'message' => 'Transport items retrieved successfully',
                'data' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve transport items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a transport item
     */
    public function addTransportItem(Request $request, int $taskId): JsonResponse
    {
        try {
            \Log::info('[addTransportItem] Request received', [
                'task_id' => $taskId,
                'data' => $request->all()
            ]);

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'quantity' => 'required|integer|min:1',
                'unit' => 'required|string|max:50',
                'category' => 'required|in:production,custom',
                'main_category' => 'nullable|in:PRODUCTION,TOOLS_EQUIPMENTS,STORES,ELECTRICALS',
                'element_category' => 'nullable|string|max:100',
                'source' => 'nullable|string|max:100',
                'weight' => 'nullable|string|max:50',
                'special_handling' => 'nullable|string|max:500',
            ]);

            \Log::info('[addTransportItem] Validation passed', ['validated' => $validated]);

            $item = $this->logisticsService->addTransportItem($taskId, $validated);

            \Log::info('[addTransportItem] Item created successfully', ['item_id' => $item->id]);

            return response()->json([
                'message' => 'Transport item added successfully',
                'data' => $item
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('[addTransportItem] Validation failed', [
                'errors' => $e->errors()
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Log::error('[addTransportItem] Failed to add transport item', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to add transport item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a transport item
     */
    public function updateTransportItem(Request $request, int $taskId, int $itemId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'quantity' => 'sometimes|required|integer|min:1',
                'unit' => 'sometimes|required|string|max:50',
                'category' => 'sometimes|required|in:production,custom',
                'main_category' => 'nullable|in:PRODUCTION,TOOLS_EQUIPMENTS,STORES,ELECTRICALS',
                'element_category' => 'nullable|string|max:100',
                'source' => 'nullable|string|max:100',
                'weight' => 'nullable|string|max:50',
                'special_handling' => 'nullable|string|max:500',
            ]);

            $item = $this->logisticsService->updateTransportItem($itemId, $validated);

            return response()->json([
                'message' => 'Transport item updated successfully',
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update transport item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a transport item
     */
    public function deleteTransportItem(int $taskId, int $itemId): JsonResponse
    {
        try {
            $this->logisticsService->removeTransportItem($itemId);

            return response()->json([
                'message' => 'Transport item deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete transport item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import production elements
     */
    public function importProductionElements(int $taskId): JsonResponse
    {
        try {
            $items = $this->logisticsService->importProductionElements($taskId);

            return response()->json([
                'message' => 'Production elements imported successfully',
                'data' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import production elements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get checklist for a task
     */
    public function getChecklist(int $taskId): JsonResponse
    {
        try {
            $checklist = $this->logisticsService->getChecklistForTask($taskId);

            return response()->json([
                'message' => 'Checklist retrieved successfully',
                'data' => $checklist
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve checklist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update checklist
     */
    public function updateChecklist(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'items' => 'required|array',
                'items.*.id' => 'required|string',
                'items.*.item_name' => 'required|string|max:255',
                'items.*.status' => 'required|in:present,missing,coming_later',
                'items.*.notes' => 'nullable|string|max:500',
                'teams' => 'required|array',
                'teams.workshop' => 'boolean',
                'teams.setup' => 'boolean',
                'teams.setdown' => 'boolean',
                'safety' => 'required|array',
                'safety.ppe' => 'boolean',
                'safety.first_aid' => 'boolean',
                'safety.fire_extinguisher' => 'boolean',
                'equipment' => 'array',
                'equipment.tools' => 'boolean',
                'equipment.vehicles' => 'boolean',
                'equipment.communication' => 'boolean',
            ]);

            $checklist = $this->logisticsService->updateChecklist($taskId, $validated);

            return response()->json([
                'message' => 'Checklist updated successfully',
                'data' => $checklist
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update checklist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate checklist from transport items
     */
    public function generateChecklist(int $taskId): JsonResponse
    {
        try {
            $checklistData = $this->logisticsService->generateChecklistFromItems($taskId);

            return response()->json([
                'message' => 'Checklist generated successfully',
                'data' => $checklistData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate checklist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get checklist statistics
     */
    public function getChecklistStats(int $taskId): JsonResponse
    {
        try {
            $stats = $this->checklistService->getChecklistStats($taskId);

            return response()->json([
                'message' => 'Checklist statistics retrieved successfully',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve checklist statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
