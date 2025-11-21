<?php

namespace App\Http\Controllers;

use App\Models\TaskMaterialsData;
use App\Models\ProjectElement;
use App\Models\ElementMaterial;
use App\Models\ElementTemplate;
use App\Models\ElementTemplateMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Materials",
 *     description="Materials management endpoints"
 * )
 */
class MaterialsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:' . \App\Constants\Permissions::TASK_UPDATE);
    }

    /**
     * Get materials data for a task
     *
     * @OA\Get(
     *     path="/api/projects/tasks/{taskId}/materials",
     *     tags={"Materials"},
     *     summary="Get materials data for a task",
     *     description="Retrieves materials data including project elements and their materials for a specific task",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Materials data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/MaterialsData"),
     *             @OA\Property(property="message", type="string", example="Materials data retrieved successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve materials data",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function getMaterialsData(int $taskId): JsonResponse
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData) {
                return response()->json([
                    'data' => $this->getDefaultMaterialsStructure($taskId),
                    'message' => 'Materials data retrieved successfully'
                ]);
            }

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData),
                'message' => 'Materials data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve materials data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get materials data by enquiry ID
     * This is useful for budget tasks to import materials from the materials task
     *
     * @OA\Get(
     *     path="/api/projects/enquiries/{enquiryId}/materials",
     *     tags={"Materials"},
     *     summary="Get materials data by enquiry ID",
     *     description="Retrieves materials data for an enquiry by finding the materials task",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="enquiryId",
     *         in="path",
     *         required=true,
     *         description="Enquiry ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Materials data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/MaterialsData"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Materials task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function getMaterialsByEnquiry(int $enquiryId): JsonResponse
    {
        try {
            // Find materials task for this enquiry
            $materialsTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $enquiryId)
                ->where('type', 'materials')
                ->first();

            if (!$materialsTask) {
                return response()->json([
                    'message' => 'Materials task not found for this enquiry',
                    'data' => null
                ], 404);
            }

            // Get materials data using the materials task ID
            return $this->getMaterialsData($materialsTask->id);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve materials data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save materials data for a task
     *
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/materials",
     *     tags={"Materials"},
     *     summary="Save materials data for a task",
     *     description="Saves or updates materials data including project elements and their materials for a specific task",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"projectInfo", "projectElements"},
     *             @OA\Property(property="projectInfo", type="object", description="Project information"),
     *             @OA\Property(
     *                 property="projectElements",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ProjectElementInput")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Materials data saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/MaterialsData"),
     *             @OA\Property(property="message", type="string", example="Materials data saved successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save materials data",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function saveMaterialsData(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'projectInfo' => 'required|array',
            'projectElements' => 'required|array',
            'projectElements.*.id' => 'required|string',
            'projectElements.*.elementType' => 'required|string',
            'projectElements.*.name' => 'required|string',
            'projectElements.*.category' => 'required|in:production,hire,outsourced',
            'projectElements.*.materials' => 'required|array',
            'projectElements.*.materials.*.description' => 'required|string',
            'projectElements.*.materials.*.unitOfMeasurement' => 'required|string',
            'projectElements.*.materials.*.quantity' => 'required|numeric|min:0',
            'availableElements' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Use database transactions for data integrity
            \DB::beginTransaction();

            // Get existing materials data to compare for changes
            $existingMaterialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();
            $existingProjectInfo = $existingMaterialsData ? $existingMaterialsData->project_info : [];
            $existingApprovalStatus = $existingProjectInfo['approval_status'] ?? null;
            
            // Determine if materials content has actually changed
            $materialsChanged = $this->haveMaterialsChanged($existingMaterialsData, $request->projectElements);

            // Reset approval status ONLY if materials have actually changed
            $projectInfo = $request->projectInfo;
            if ($materialsChanged && $existingApprovalStatus) {
                \Log::info('Materials content changed - resetting approval status', ['taskId' => $taskId]);
                $projectInfo['approval_status'] = [
                    'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'System: Reset due to material changes'],
                    'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'System: Reset due to material changes'],
                    'finance' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'System: Reset due to material changes'],
                    'all_approved' => false,
                    'last_approval_at' => null
                ];
            } elseif (!$materialsChanged && $existingApprovalStatus) {
                // Preserve existing approval status if materials haven't changed
                \Log::info('Materials unchanged - preserving approval status', ['taskId' => $taskId]);
                $projectInfo['approval_status'] = $existingApprovalStatus;
            } else {
                // Initialize approval status for new materials data
                $projectInfo['approval_status'] = [
                    'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'finance' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'all_approved' => false,
                    'last_approval_at' => null
                ];
            }

            $materialsData = TaskMaterialsData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $projectInfo,
                    'updated_at' => now()
                ]
            );

            // Delete existing elements and materials (cascade delete will handle materials)
            $materialsData->elements()->delete();

            // Save new elements and materials
            foreach ($request->projectElements as $elementData) {
                $element = ProjectElement::create([
                    'task_materials_data_id' => $materialsData->id,
                    'template_id' => $elementData['templateId'] ?? null,
                    'element_type' => $elementData['elementType'],
                    'name' => $elementData['name'],
                    'category' => $elementData['category'],
                    'dimensions' => $elementData['dimensions'] ?? [],
                    'is_included' => $elementData['isIncluded'] ?? true,
                    'notes' => $elementData['notes'] ?? null,
                    'sort_order' => $elementData['sortOrder'] ?? 0,
                ]);

                foreach ($elementData['materials'] as $materialData) {
                    ElementMaterial::create([
                        'project_element_id' => $element->id,
                        'description' => $materialData['description'],
                        'unit_of_measurement' => $materialData['unitOfMeasurement'],
                        'quantity' => $materialData['quantity'],
                        'is_included' => $materialData['isIncluded'] ?? true,
                        'is_additional' => $materialData['isAdditional'] ?? false,  // ← ADD THIS LINE
                        'notes' => $materialData['notes'] ?? null,
                        'sort_order' => $materialData['sortOrder'] ?? 0,
                    ]);
                }
            }

            \DB::commit();

            // Check for additional materials and create budget additions automatically
            $this->createBudgetAdditionsForAdditionalMaterials($taskId, $request->projectElements);

            // Update budget data with latest materials if budget exists
            $this->syncMaterialsToBudget($taskId, $request->projectElements);

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData->fresh(['elements.materials'])),
                'message' => 'Materials data saved successfully'
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error('Failed to save materials data', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to save materials data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get element templates
     *
     * @OA\Get(
     *     path="/api/projects/element-templates",
     *     tags={"Materials"},
     *     summary="Get element templates",
     *     description="Retrieves all active element templates with their default materials",
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Element templates retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ElementTemplate")
     *             ),
     *             @OA\Property(property="message", type="string", example="Element templates retrieved successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve element templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function getElementTemplates(): JsonResponse
    {
        try {
            $templates = ElementTemplate::where('is_active', true)
                ->with('materials')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'data' => $templates->map(function ($template) {
                    return [
                        'id' => $template->name,
                        'name' => $template->name,
                        'displayName' => $template->display_name,
                        'description' => $template->description,
                        'category' => $template->category,
                        'color' => $template->color,
                        'order' => $template->sort_order,
                        'defaultMaterials' => $template->materials->map(function ($material) {
                            return [
                                'id' => $material->id,
                                'description' => $material->description,
                                'unitOfMeasurement' => $material->unit_of_measurement,
                                'defaultQuantity' => $material->default_quantity,
                                'isDefaultIncluded' => $material->is_default_included,
                                'order' => $material->sort_order,
                            ];
                        })
                    ];
                }),
                'message' => 'Element templates retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve element templates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new element template
     *
     * @OA\Post(
     *     path="/api/projects/element-templates",
     *     tags={"Materials"},
     *     summary="Create element template",
     *     description="Creates a new element template with default materials",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "displayName", "category", "defaultMaterials"},
     *             @OA\Property(property="name", type="string", description="Template name (unique)", example="stage_platform"),
     *             @OA\Property(property="displayName", type="string", description="Display name", example="Stage Platform"),
     *             @OA\Property(property="description", type="string", description="Template description"),
     *             @OA\Property(property="category", type="string", enum={"structure", "decoration", "flooring", "technical", "furniture", "branding", "custom"}, example="structure"),
     *             @OA\Property(property="color", type="string", description="Template color", example="blue"),
     *             @OA\Property(
     *                 property="defaultMaterials",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ElementTemplateMaterialInput")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Element template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/ElementTemplate"),
     *             @OA\Property(property="message", type="string", example="Element template created successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create element template",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function createElementTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:element_templates,name',
            'displayName' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|in:structure,decoration,flooring,technical,furniture,branding,custom',
            'color' => 'nullable|string|max:20',
            'defaultMaterials' => 'required|array|min:1',
            'defaultMaterials.*.description' => 'required|string',
            'defaultMaterials.*.unitOfMeasurement' => 'required|string',
            'defaultMaterials.*.defaultQuantity' => 'required|numeric|min:0',
            'defaultMaterials.*.isDefaultIncluded' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $template = ElementTemplate::create([
                'name' => $request->name,
                'display_name' => $request->displayName,
                'description' => $request->description,
                'category' => $request->category,
                'color' => $request->color ?? 'blue',
                'sort_order' => ElementTemplate::max('sort_order') + 1
            ]);

            // Create default materials
            foreach ($request->defaultMaterials as $materialData) {
                ElementTemplateMaterial::create([
                    'element_template_id' => $template->id,
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unitOfMeasurement'],
                    'default_quantity' => $materialData['defaultQuantity'],
                    'is_default_included' => $materialData['isDefaultIncluded'] ?? true,
                    'sort_order' => $materialData['order'] ?? 0
                ]);
            }

            return response()->json([
                'data' => $template->load('materials'),
                'message' => 'Element template created successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create element template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default materials structure for new tasks
     */
    private function getDefaultMaterialsStructure(int $taskId): array
    {
        try {
            $task = \App\Modules\Projects\Models\EnquiryTask::with('enquiry')->find($taskId);

            if (!$task) {
                \Log::warning('Task not found for materials structure', ['taskId' => $taskId]);
                return [
                    'projectInfo' => [
                        'projectId' => "WNG-11-2025-{$taskId}",
                        'enquiryTitle' => 'Untitled Project',
                        'clientName' => 'Unknown Client',
                        'eventVenue' => 'Venue TBC',
                        'setupDate' => 'Date TBC',
                        'setDownDate' => 'TBC'
                    ],
                    'projectElements' => [],
                    'availableElements' => []
                ];
            }

            return [
                'projectInfo' => [
                    'projectId' => $task->enquiry->enquiry_number ?? "WNG-11-2025-{$taskId}",
                    'enquiryTitle' => $task->enquiry->title ?? 'Untitled Project',
                    'clientName' => $task->enquiry->client->full_name ?? 'Unknown Client',
                    'eventVenue' => $task->enquiry->venue ?? 'Venue TBC',
                    'setupDate' => $task->enquiry->expected_delivery_date ?? 'Date TBC',
                    'setDownDate' => 'TBC'
                ],
                'projectElements' => [],
                'availableElements' => $this->getElementTemplates()->getData()->data ?? []
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to get default materials structure', [
                'taskId' => $taskId,
                'error' => $e->getMessage()
            ]);

            // Return safe fallback
            return [
                'projectInfo' => [
                    'projectId' => "WNG-11-2025-{$taskId}",
                    'enquiryTitle' => 'Untitled Project',
                    'clientName' => 'Unknown Client',
                    'eventVenue' => 'Venue TBC',
                    'setupDate' => 'Date TBC',
                    'setDownDate' => 'TBC'
                ],
                'projectElements' => [],
                'availableElements' => []
            ];
        }
    }

    /**
     * Sync materials data to budget whenever materials are updated
     */
    private function syncMaterialsToBudget(int $materialsTaskId, array $projectElements): void
    {
        try {
            // Find the budget task for this enquiry
            $materialsTask = \App\Modules\Projects\Models\EnquiryTask::find($materialsTaskId);
            if (!$materialsTask) {
                \Log::warning('Materials task not found for budget sync', ['taskId' => $materialsTaskId]);
                return;
            }

            $budgetTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $materialsTask->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) {
                \Log::info('No budget task found for enquiry - skipping materials sync', [
                    'enquiryId' => $materialsTask->project_enquiry_id
                ]);
                return;
            }

            // Get budget data
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            if (!$budgetData) {
                \Log::info('No budget data found - skipping materials sync', [
                    'budgetTaskId' => $budgetTask->id
                ]);
                return;
            }

            // Get materials data to sync
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTaskId)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData) {
                \Log::info('No materials data found - skipping materials sync', [
                    'materialsTaskId' => $materialsTaskId
                ]);
                return;
            }

            // Check approval status - ONLY sync if fully approved
            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? [];
            $isApproved = $approvalStatus['all_approved'] ?? false;

            if (!$isApproved) {
                \Log::info('Materials not fully approved - skipping automatic budget sync', [
                    'materialsTaskId' => $materialsTaskId,
                    'approvalStatus' => $approvalStatus
                ]);
                return;
            }

            // Transform materials for budget
            $budgetMaterials = [];
            foreach ($materialsData->elements as $element) {
                // Skip elements that are not included
                if (!$element->is_included) {
                    continue;
                }

                $elementMaterials = [];

                foreach ($element->materials as $material) {
                    // Skip materials that are not included
                    if (!$material->is_included) {
                        continue;
                    }

                    // Skip materials marked as additional - they should only appear in additions tab
                    if ($material->is_additional) {
                        continue;
                    }

                    $elementMaterials[] = [
                        'id' => (string) $material->id,
                        'description' => $material->description,
                        'unitOfMeasurement' => $material->unit_of_measurement,
                        'quantity' => (float) $material->quantity,
                        'isIncluded' => true, // Already filtered
                        'unitPrice' => 0.0, // To be filled by budget user
                        'totalPrice' => 0.0, // Calculated: quantity * unitPrice
                        'isAddition' => false,
                        'notes' => $material->notes,
                        'category' => $element->category
                    ];
                }

                // Only add element if it has materials
                if (!empty($elementMaterials)) {
                    $budgetMaterials[] = [
                        'id' => (string) $element->id,
                        'elementType' => $element->element_type,
                        'name' => $element->name,
                        'category' => $element->category,
                        'materials' => $elementMaterials,
                        'isIncluded' => true, // Already filtered
                        'notes' => $element->notes
                    ];
                }
            }

            // Merge with existing budget data to preserve prices
            $existingMaterials = $budgetData->materials_data ?? [];
            
            // Create a lookup map of existing materials by element and material description
            $existingPricesMap = [];
            foreach ($existingMaterials as $existingElement) {
                $elementKey = $existingElement['elementType'] . '_' . $existingElement['name'];
                foreach ($existingElement['materials'] ?? [] as $existingMaterial) {
                    $materialKey = $elementKey . '_' . $existingMaterial['description'];
                    $existingPricesMap[$materialKey] = [
                        'unitPrice' => $existingMaterial['unitPrice'] ?? 0.0,
                        'totalPrice' => $existingMaterial['totalPrice'] ?? 0.0,
                    ];
                }
            }
            
            // Merge new materials with existing price data
            $mergedMaterials = [];
            foreach ($budgetMaterials as $newElement) {
                $elementKey = $newElement['elementType'] . '_' . $newElement['name'];
                $mergedElement = $newElement;
                
                foreach ($mergedElement['materials'] as &$newMaterial) {
                    $materialKey = $elementKey . '_' . $newMaterial['description'];
                    
                    // If this material existed before, preserve its prices
                    if (isset($existingPricesMap[$materialKey])) {
                        $newMaterial['unitPrice'] = $existingPricesMap[$materialKey]['unitPrice'];
                        // Recalculate total price with new quantity but existing unit price
                        $newMaterial['totalPrice'] = $newMaterial['quantity'] * $newMaterial['unitPrice'];
                        
                        \Log::info('Preserved price for material', [
                            'material' => $newMaterial['description'],
                            'unitPrice' => $newMaterial['unitPrice'],
                            'quantity' => $newMaterial['quantity']
                        ]);
                    }
                }
                
                $mergedMaterials[] = $mergedElement;
            }

            // Update budget with merged materials data
            $budgetData->update([
                'materials_data' => $mergedMaterials,
                'materials_imported_at' => now(),
                'materials_imported_from_task' => $materialsTaskId,
                'materials_manually_modified' => false,
                'materials_import_metadata' => [
                    'imported_at' => now()->toISOString(),
                    'materials_task_id' => $materialsTaskId,
                    'materials_task_title' => $materialsTask->title,
                    'total_elements' => $materialsData->elements->count(),
                    'total_materials' => $materialsData->elements->sum(function ($element) {
                        return $element->materials->count();
                    })
                ],
                'updated_at' => now()
            ]);

            \Log::info("Materials synced to budget successfully", [
                'budget_task_id' => $budgetTask->id,
                'materials_task_id' => $materialsTaskId,
                'total_elements' => count($budgetMaterials),
                'total_materials' => array_sum(array_map(function ($element) {
                    return count($element['materials']);
                }, $budgetMaterials))
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to sync materials to budget', [
                'materialsTaskId' => $materialsTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Create budget additions for materials marked as additional
     */
    private function createBudgetAdditionsForAdditionalMaterials(int $materialsTaskId, array $projectElements): void
    {
        try {
            // Find the budget task for this enquiry
            $materialsTask = \App\Modules\Projects\Models\EnquiryTask::find($materialsTaskId);
            if (!$materialsTask) {
                \Log::warning('Materials task not found for budget addition creation', ['taskId' => $materialsTaskId]);
                return;
            }

            // Check approval status - ONLY create additions if fully approved
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTaskId)->first();
            if ($materialsData) {
                $projectInfo = $materialsData->project_info ?? [];
                $approvalStatus = $projectInfo['approval_status'] ?? [];
                $isApproved = $approvalStatus['all_approved'] ?? false;

                if (!$isApproved) {
                    \Log::info('Materials not fully approved - skipping automatic budget additions', [
                        'materialsTaskId' => $materialsTaskId
                    ]);
                    return;
                }
            }

            $budgetTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $materialsTask->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) {
                \Log::info('No budget task found for enquiry - skipping automatic budget additions', [
                    'enquiryId' => $materialsTask->project_enquiry_id
                ]);
                return;
            }

            // Check if budget task is completed - if so, all new materials should be additions
            $isBudgetCompleted = $budgetTask->status === 'completed';

            // Get budget data
            $budgetData = \App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            if (!$budgetData) {
                \Log::info('No budget data found - skipping automatic budget additions', [
                    'budgetTaskId' => $budgetTask->id
                ]);
                return;
            }

            // Process each element and its materials
            foreach ($projectElements as $elementData) {
                foreach ($elementData['materials'] as $materialData) {
                    // If budget is completed, ALL new materials should be treated as additions
                    // Otherwise, only materials explicitly marked as "additional"
                    $shouldCreateAddition = $isBudgetCompleted ||
                        (isset($materialData['isAdditional']) && $materialData['isAdditional']);

                    if ($shouldCreateAddition) {
                        // Check if this material already has a budget addition
                        $existingAddition = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)
                            ->where('title', 'Additional: ' . $materialData['description'])
                            ->where('status', '!=', 'rejected')
                            ->first();

                        if (!$existingAddition) {
                            // Create new budget addition
                            $additionTitle = $isBudgetCompleted
                                ? 'Post-Budget Addition: ' . $materialData['description']
                                : 'Additional: ' . $materialData['description'];

                            $additionDescription = $isBudgetCompleted
                                ? 'Automatically created from Materials Task after budget completion - Element: ' . $elementData['name']
                                : 'Automatically created from Materials Task - Element: ' . $elementData['name'];

                            \App\Models\BudgetAddition::create([
                                'task_budget_data_id' => $budgetData->id,
                                'title' => $additionTitle,
                                'description' => $additionDescription,
                                'materials' => [
                                    [
                                        'id' => 'auto_' . uniqid(),
                                        'description' => $materialData['description'],
                                        'unitOfMeasurement' => $materialData['unitOfMeasurement'],
                                        'quantity' => $materialData['quantity'],
                                        'unitPrice' => 0, // To be set in budget
                                        'totalPrice' => 0,
                                        'isAddition' => true
                                    ]
                                ],
                                'labour' => [],
                                'expenses' => [],
                                'logistics' => [],
                                'status' => 'pending_approval',
                                'created_by' => auth()->id() ?? 1, // System or current user
                            ]);

                            \Log::info('Created automatic budget addition for material', [
                                'material' => $materialData['description'],
                                'budgetId' => $budgetData->id,
                                'isPostBudgetAddition' => $isBudgetCompleted,
                                'budgetStatus' => $budgetTask->status
                            ]);
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to create automatic budget additions', [
                'materialsTaskId' => $materialsTaskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Format materials data for frontend
     */
    private function formatMaterialsData(TaskMaterialsData $materialsData): array
    {
        try {
            return [
                'projectInfo' => $materialsData->project_info ?? [],
                'projectElements' => $materialsData->elements->map(function ($element) {
                    return [
                        'id' => (string) $element->id,
                        'templateId' => $element->template_id,
                        'elementType' => $element->element_type,
                        'name' => $element->name,
                        'category' => $element->category,
                        'dimensions' => $element->dimensions ?? ['length' => '', 'width' => '', 'height' => ''],
                        'isIncluded' => (bool) $element->is_included,
                        'materials' => $element->materials->map(function ($material) {
                            return [
                                'id' => (string) $material->id,
                                'description' => $material->description,
                                'unitOfMeasurement' => $material->unit_of_measurement,
                                'quantity' => (float) $material->quantity,
                                'isIncluded' => (bool) $material->is_included,
                'isAdditional' => (bool) $material->is_additional,  // ← ADD THIS LINE
                                'notes' => $material->notes,
                                'createdAt' => $material->created_at?->toISOString(),
                                'updatedAt' => $material->updated_at?->toISOString(),
                            ];
                        })->toArray(),
                        'notes' => $element->notes,
                        'addedAt' => $element->created_at?->toISOString(),
                    ];
                })->toArray(),
                'availableElements' => $this->getElementTemplates()->getData()->data ?? []
            ];
        } catch (\Exception $e) {
            \Log::error('Failed to format materials data', [
                'materialsDataId' => $materialsData->id,
                'error' => $e->getMessage()
            ]);

            // Return safe fallback structure
            return [
                'projectInfo' => $materialsData->project_info ?? [],
                'projectElements' => [],
                'availableElements' => []
            ];
        }
    }

    /**
     * Approve materials for a specific department
     *
     * @param int $taskId Materials task ID
     * @param string $department Department name (design, production, finance)
     */
    public function approveMaterials(Request $request, int $taskId, string $department): JsonResponse
    {
        $validator = Validator::make(['department' => $department], [
            'department' => 'required|in:design,production,finance'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid department',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();

            if (!$materialsData) {
                return response()->json([
                    'message' => 'Materials data not found for this task'
                ], 404);
            }

            // Get current approval status from project_info
            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? [
                'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'finance' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'all_approved' => false,
                'last_approval_at' => null
            ];

            // Update approval for this department
            $user = auth()->user();
            $approvalStatus[$department] = [
                'approved' => true,
                'approved_by' => $user->id,
                'approved_by_name' => $user->name,
                'approved_at' => now()->toISOString(),
                'comments' => $request->input('comments', '')
            ];

            // Check if all departments approved
            $allApproved = $approvalStatus['design']['approved'] &&
                           $approvalStatus['production']['approved'] &&
                           $approvalStatus['finance']['approved'];

            $approvalStatus['all_approved'] = $allApproved;
            if ($allApproved) {
                $approvalStatus['last_approval_at'] = now()->toISOString();
            }

            // Update project info with new approval status
            $projectInfo['approval_status'] = $approvalStatus;
            $materialsData->update(['project_info' => $projectInfo]);

            // If fully approved, trigger budget sync and additions creation
            if ($allApproved) {
                \Log::info('Materials fully approved - triggering budget sync', ['taskId' => $taskId]);

                // Reconstruct projectElements for additions creation (needs camelCase)
                $materialsData->load('elements.materials');
                $projectElements = $materialsData->elements->map(function($element) {
                    return [
                        'name' => $element->name,
                        'materials' => $element->materials->map(function($material) {
                            return [
                                'description' => $material->description,
                                'unitOfMeasurement' => $material->unit_of_measurement,
                                'quantity' => $material->quantity,
                                'isAdditional' => (bool) $material->is_additional
                            ];
                        })->toArray()
                    ];
                })->toArray();

                $this->createBudgetAdditionsForAdditionalMaterials($taskId, $projectElements);
                $this->syncMaterialsToBudget($taskId, []); // Pass empty array as it fetches from DB
            }

            return response()->json([
                'message' => ucfirst($department) . ' approval recorded successfully',
                'approval_status' => $approvalStatus
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to approve materials', [
                'taskId' => $taskId,
                'department' => $department,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to approve materials',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get approval status for materials
     *
     * @param int $taskId Materials task ID
     */
    public function getApprovalStatus(int $taskId): JsonResponse
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();

            if (!$materialsData) {
                // Return default approval structure if no materials data exists
                return response()->json([
                    'approval_status' => [
                        'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'finance' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'all_approved' => false,
                        'last_approval_at' => null
                    ],
                    'pending' => ['design', 'production', 'finance']
                ]);
            }

            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? [
                'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'finance' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'all_approved' => false,
                'last_approval_at' => null
            ];

            // Calculate pending departments
            $pending = [];
            foreach (['design', 'production', 'finance'] as $dept) {
                if (!($approvalStatus[$dept]['approved'] ?? false)) {
                    $pending[] = $dept;
                }
            }

            return response()->json([
                'approval_status' => $approvalStatus,
                'pending' => $pending,
                'all_approved' => $approvalStatus['all_approved'] ?? false
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get approval status', [
                'taskId' => $taskId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to get approval status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check if materials content has actually changed
     * Compares existing materials data with incoming project elements
     * 
     * @param TaskMaterialsData|null $existingData
     * @param array $newProjectElements
     * @return bool
     */
    private function haveMaterialsChanged(?TaskMaterialsData $existingData, array $newProjectElements): bool
    {
        // If no existing data, this is a new entry (not a change)
        if (!$existingData) {
            return false;
        }

        // Load existing elements with materials
        $existingData->load('elements.materials');
        $existingElements = $existingData->elements;

        // Quick check: different number of elements = changed
        if ($existingElements->count() !== count($newProjectElements)) {
            \Log::info('Materials changed: different element count', [
                'existing' => $existingElements->count(),
                'new' => count($newProjectElements)
            ]);
            return true;
        }

        // Create a normalized representation of existing materials for comparison
        $existingNormalized = [];
        foreach ($existingElements as $element) {
            $elementKey = $element->element_type . '_' . $element->name;
            $existingNormalized[$elementKey] = [
                'element_type' => $element->element_type,
                'name' => $element->name,
                'category' => $element->category,
                'materials' => $element->materials->map(function($material) {
                    return [
                        'description' => $material->description,
                        'unit_of_measurement' => $material->unit_of_measurement,
                        'quantity' => (float) $material->quantity,
                        'is_additional' => (bool) $material->is_additional
                    ];
                })->sortBy('description')->values()->toArray()
            ];
        }

        // Create a normalized representation of new materials
        $newNormalized = [];
        foreach ($newProjectElements as $element) {
            $elementKey = $element['elementType'] . '_' . $element['name'];
            $materials = $element['materials'] ?? [];
            usort($materials, function($a, $b) {
                return strcmp($a['description'] ?? '', $b['description'] ?? '');
            });
            
            $newNormalized[$elementKey] = [
                'element_type' => $element['elementType'],
                'name' => $element['name'],
                'category' => $element['category'],
                'materials' => array_map(function($material) {
                    return [
                        'description' => $material['description'],
                        'unit_of_measurement' => $material['unitOfMeasurement'],
                        'quantity' => (float) $material['quantity'],
                        'is_additional' => (bool) ($material['isAdditional'] ?? false)
                    ];
                }, $materials)
            ];
        }

        // Compare normalized data
        $changed = json_encode($existingNormalized) !== json_encode($newNormalized);
        
        if ($changed) {
            \Log::info('Materials changed: content differs');
        }
        
        return $changed;
    }
}
