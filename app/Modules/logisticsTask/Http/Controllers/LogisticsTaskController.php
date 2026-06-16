<?php

namespace App\Modules\logisticsTask\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\logisticsTask\Services\LogisticsTaskService;
use App\Modules\logisticsTask\Services\TransportItemService;
use App\Modules\logisticsTask\Services\LogisticsChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Modules\Projects\Models\EnquiryTask;

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
                ]);
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
                'crew_vehicle' => 'nullable|string|max:100',
                'driver_name' => 'nullable|string|max:100',
                'driver_contact' => 'nullable|string|max:20',
                'team_captain' => 'nullable|string|max:100',
                'prepared_by' => 'nullable|string|max:100',
                'route.origin' => 'nullable|string|max:255',
                'route.destination' => 'nullable|string|max:255',
                'route.distance' => 'nullable|numeric|min:0',
                'route.travel_time' => 'nullable|string|max:50',
                'timeline.loading_time' => 'nullable|string|max:20',
                'timeline.departure_time' => 'nullable|string|max:20',
                'timeline.arrival_time' => 'nullable|string|max:20',
                'timeline.setup_start_time' => 'nullable|string|max:20',
                'timeline.setup_start_hour' => 'nullable|string|max:20',
                'timeline.setup_duration' => 'nullable|string|max:100',
                'timeline.setdown_date' => 'nullable|string|max:20',
                'timeline.setdown_time' => 'nullable|string|max:20',
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
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'quantity' => 'required|integer|min:1',
                'unit' => 'required|string|max:50',
                'category' => 'nullable|in:production,custom',
                'main_category' => 'nullable|in:PRODUCTION,TOOLS_EQUIPMENTS,STORES,ELECTRICALS,CLIENT_ASSETS',
                'is_returnable' => 'nullable|boolean',
                'sub_type' => 'nullable|string|max:50',
                'element_category' => 'nullable|string|max:100',
                'source' => 'nullable|string|max:100',
                'weight' => 'nullable|string|max:50',
                'special_handling' => 'nullable|string|max:500',
            ]);

            // Set default category if not provided based on main_category
            if (empty($validated['category'])) {
                if (isset($validated['main_category']) && $validated['main_category'] === 'PRODUCTION') {
                    $validated['category'] = 'production';
                } else {
                    $validated['category'] = 'custom';
                }
            }

            $item = $this->logisticsService->addTransportItem($taskId, $validated);

            return response()->json([
                'message' => 'Transport item added successfully',
                'data' => $item
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
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
                'category' => 'nullable|in:production,custom',
                'main_category' => 'nullable|in:PRODUCTION,TOOLS_EQUIPMENTS,STORES,ELECTRICALS,CLIENT_ASSETS',
                'is_returnable' => 'nullable|boolean',
                'sub_type' => 'nullable|string|max:50',
                'element_category' => 'nullable|string|max:100',
                'source' => 'nullable|string|max:100',
                'weight' => 'nullable|string|max:50',
                'special_handling' => 'nullable|string|max:500',
            ]);

            // Set default category if not provided based on main_category
            if (isset($validated['main_category']) && empty($validated['category'])) {
                if ($validated['main_category'] === 'PRODUCTION') {
                    $validated['category'] = 'production';
                } else {
                    $validated['category'] = 'custom';
                }
            }

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
                'items'                          => 'required|array',
                'items.*.id'                     => 'required|string',
                'items.*.item_name'              => 'required|string|max:255',
                'items.*.status'                 => 'required|in:present,missing,coming_later',
                'items.*.notes'                  => 'nullable|string|max:500',
                'items.*.checkedBy'              => 'nullable|string|max:255',
                'items.*.checkedAt'              => 'nullable',
                'teams'                          => 'nullable|array',
                'teams.workshop'                 => 'nullable|boolean',
                'teams.setup'                    => 'nullable|boolean',
                'teams.setdown'                  => 'nullable|boolean',
                'safety'                         => 'required|array',
                'safety.ppe'                     => 'boolean',
                'safety.first_aid'               => 'boolean',
                'safety.fire_extinguisher'       => 'boolean',
                'equipment'                      => 'nullable|array',
                'equipment.tools'                => 'nullable|boolean',
                'equipment.vehicles'             => 'nullable|boolean',
                'equipment.communication'        => 'nullable|boolean',
                'return_items'                   => 'nullable|array',
                'return_items.*.id'              => 'nullable|string',
                'return_items.*.name'            => 'nullable|string|max:255',
                'return_items.*.quantity_dispatched' => 'nullable|integer|min:0',
                'return_items.*.quantity_returned'   => 'nullable|integer|min:0',
                'return_items.*.unit'            => 'nullable|string|max:50',
                'return_items.*.main_category'   => 'nullable|string',
                'return_items.*.status'          => 'nullable|in:pending,returned,partial,missing,damaged',
                'return_items.*.condition'       => 'nullable|in:good,worn,damaged',
                'return_items.*.notes'           => 'nullable|string|max:500',
                'return_items.*.returned_at'     => 'nullable|string',
                'setdown_confirmed'              => 'nullable|boolean',
                'return_authorized'              => 'nullable|boolean',
                'return_authorized_at'           => 'nullable|string',
                'setdown_notes'                  => 'nullable|string|max:1000',
            ]);

            $checklist = $this->logisticsService->updateChecklist($taskId, $validated);

            return response()->json([
                'message' => 'Checklist updated successfully',
                'data' => $checklist
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed for checklist update', [
                'errors' => $e->errors(),
                'data' => $request->all()
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Failed to update checklist: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
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

    /**
     * Generate return checklist from returnable manifest items.
     * Merges into existing checklist_data — safe to call multiple times (idempotent).
     */
    public function generateReturnChecklist(int $taskId): JsonResponse
    {
        try {
            $returnItems = $this->logisticsService->generateReturnChecklistItems($taskId);

            return response()->json([
                'message' => 'Return checklist generated successfully',
                'data'    => $returnItems,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate return checklist',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Authorize return as complete — stamps return_authorized + timestamp in checklist_data.
     */
    public function authorizeReturn(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $checklist = $this->logisticsService->authorizeReturn($taskId, $validated['notes'] ?? null);

            return response()->json([
                'message' => 'Return authorized successfully',
                'data'    => $checklist,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to authorize return',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download loading checklist as PDF
     */
    public function downloadChecklistPdf(int $taskId)
    {
        try {
            $data = $this->logisticsService->getLogisticsForTask($taskId);

            if (!$data) {
                return response()->json(['message' => 'No logistics data found.'], 404);
            }

            $task = EnquiryTask::with(['enquiry.client', 'enquiry.project'])->findOrFail($taskId);

            $pdf = Pdf::loadView('reports.logistics-loading-checklist', [
                'data'   => $data,
                'task'   => $task,
                'client' => $task->enquiry->client ?? null,
            ]);

            $ref = optional($task->enquiry->project)->project_id ?? $task->enquiry->enquiry_number ?? $taskId;
            return $pdf->download('loading-checklist-' . $ref . '.pdf');
        } catch (\Exception $e) {
            Log::error("Failed to generate loading checklist PDF for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to generate PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download return checklist as PDF
     */
    public function downloadReturnChecklistPdf(int $taskId)
    {
        try {
            $data = $this->logisticsService->getLogisticsForTask($taskId);

            if (!$data) {
                return response()->json(['message' => 'No logistics data found.'], 404);
            }

            $task = EnquiryTask::with(['enquiry.client', 'enquiry.project'])->findOrFail($taskId);

            $pdf = Pdf::loadView('reports.logistics-return-checklist', [
                'data'   => $data,
                'task'   => $task,
                'client' => $task->enquiry->client ?? null,
            ]);

            $ref = optional($task->enquiry->project)->project_id ?? $task->enquiry->enquiry_number ?? $taskId;
            return $pdf->download('return-checklist-' . $ref . '.pdf');
        } catch (\Exception $e) {
            Log::error("Failed to generate return checklist PDF for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to generate PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate PDF report
     */
    public function generatePdf(int $taskId)
    {
        try {
            Log::info("Generating PDF for logistics task {$taskId}");
            
            $data = $this->logisticsService->getLogisticsForTask($taskId);
            
            if (!$data) {
                Log::warning("No logistics data found for task {$taskId}");
                return response()->json([
                    'success' => false,
                    'message' => 'No logistics data found. Please add logistics planning details first.'
                ], 404);
            }

            $task = EnquiryTask::with(['enquiry.client', 'enquiry.project'])->findOrFail($taskId);
            
            Log::info("Generating PDF with data", [
                'has_planning' => isset($data['planning']),
                'has_transport' => isset($data['transport_items']),
                'has_checklist' => isset($data['checklist'])
            ]);
            
            $pdf = Pdf::loadView('reports.logistics', [
                'data' => $data,
                'task' => $task,
                'project' => $task->enquiry->project ?? null,
                'client' => $task->enquiry->client ?? null
            ]);
            
            $projectCode = optional($task->enquiry->project)->project_id ?? $task->enquiry->enquiry_number ?? $taskId;
            $filename = 'logistics-report-' . $projectCode . '.pdf';
            
            Log::info("PDF generated successfully for task {$taskId}");
            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error("Failed to generate PDF for task {$taskId}: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
