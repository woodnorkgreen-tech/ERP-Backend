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

            $materialsData = TaskMaterialsData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $request->projectInfo,
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
                        'notes' => $materialData['notes'] ?? null,
                        'sort_order' => $materialData['sortOrder'] ?? 0,
                    ]);
                }
            }

            \DB::commit();

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
                        'projectId' => "ENQ-{$taskId}",
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
                    'projectId' => $task->enquiry->enquiry_number ?? "ENQ-{$taskId}",
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
                    'projectId' => "ENQ-{$taskId}",
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
}
