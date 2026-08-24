<?php

namespace App\Http\Controllers;

use App\Events\MaterialsListChanged;

use App\Models\TaskMaterialsData;
use App\Models\MaterialVersion;
use App\Models\ProjectElement;
use App\Models\ElementMaterial;
use App\Models\ElementTemplate;
use App\Models\ElementTemplateMaterial;
use App\Models\TaskQuoteData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * @OA\Tag(
 *     name="Materials",
 *     description="Materials management endpoints"
 * )
 */
class MaterialsController extends Controller
{
    protected $procurementService;
    protected \App\Modules\logisticsTask\Services\LogisticsTaskService $logisticsService;

    public function __construct(
        \App\Services\ProcurementService $procurementService,
        \App\Modules\logisticsTask\Services\LogisticsTaskService $logisticsService
    ) {
        $this->procurementService = $procurementService;
        $this->logisticsService = $logisticsService;
    }

    /**
     * Push selected materials elements onto this enquiry's Logistics task
     * loading sheet (transport items).
     */
    public function pushParticularsToLogistics(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'material_ids' => 'required|array|min:1',
            'material_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $items = $this->logisticsService->pushMaterialParticularsToLogistics(
                $taskId,
                $request->input('material_ids')
            );

            return response()->json([
                'message' => count($items) . ' item(s) pushed to the Logistics loading sheet.',
                'data' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to push materials to Logistics.',
                'error' => $e->getMessage(),
            ], 422);
        }
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
                    'designGate' => $this->checkDesignApprovalGate($taskId),
                    'message' => 'Materials data retrieved successfully'
                ]);
            }

            $gate = $this->checkDesignApprovalGate($taskId);

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData),
                'designGate' => $gate,
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
     * Download materials data as PDF
     */
    public function downloadPdf(int $taskId)
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)
                ->with(['elements.materials', 'task.enquiry.client'])
                ->first();

            if (!$materialsData) {
                return response()->json([
                    'message' => 'Materials data not found'
                ], 404);
            }

            $enquiry = $materialsData->task->enquiry;
            
            $pdf = Pdf::loadView('reports.materials', [
                'materialsData' => $materialsData,
                'enquiry' => $enquiry
            ]);

            $fileName = 'materials-specification-' . ($enquiry->job_number ?? $enquiry->enquiry_number ?? $taskId) . '.pdf';
            
            return $pdf->download($fileName);
        } catch (\Exception $e) {
            \Log::error('Failed to generate materials PDF', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to generate PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
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
    public function getMaterialsConfig(): JsonResponse
    {
        return response()->json([
            'element_types' => config('materials.element_types', []),
            'categories' => config('materials.categories', []),
            'units' => config('materials.units', []),
            'included_options' => config('materials.included_options', []),
        ]);
    }

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
     * Preview the approved quote snapshot that can seed the materials list.
     */
    public function previewApprovedQuoteImport(int $taskId): JsonResponse
    {
        try {
            $preview = $this->buildApprovedQuoteImportPreview($taskId);

            return response()->json([
                'data' => $preview,
                'message' => 'Approved quote snapshot ready for import'
            ]);
        } catch (\RuntimeException $e) {
            $status = $this->clientErrorStatus($e);

            if ($status) {
                return response()->json(['message' => $e->getMessage()], $status);
            }

            throw $e;
        } catch (\Exception $e) {
            \Log::error('Failed to preview approved quote import', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to preview approved quote import',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Import materials from the approved quote snapshot into this materials task.
     */
    public function importApprovedQuote(Request $request, int $taskId): JsonResponse
    {
        $this->authorizeMaterialsMutation($taskId);

        $validator = Validator::make($request->all(), [
            'selected_element_ids' => 'sometimes|array',
            'selected_element_ids.*' => 'nullable',
            'selectedElementIds' => 'sometimes|array',
            'selectedElementIds.*' => 'nullable',
            'force' => 'sometimes|boolean',
            'editReason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $selectedElementIds = $request->has('selected_element_ids')
            ? $request->input('selected_element_ids')
            : ($request->has('selectedElementIds') ? $request->input('selectedElementIds') : null);

        try {
            $preview = $this->buildApprovedQuoteImportPreview($taskId, $selectedElementIds);
            $elements = $preview['materials'];

            if (empty($elements)) {
                throw $this->clientError('No selected approved quote materials are available to import.', 422);
            }

            $existingMaterialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)
                ->withCount('elements')
                ->first();

            if ($existingMaterialsData && $existingMaterialsData->elements_count > 0 && !$request->boolean('force')) {
                throw $this->clientError('Materials already exist for this task. Pass force=true to replace them from the approved quote snapshot.', 409);
            }

            // NOTE: Re-importing over an approved base no longer requires a reason here —
            // the gate now lives on the Project Officer's re-approval instead.
            $materialsData = DB::transaction(function () use ($taskId, $existingMaterialsData, $preview, $elements) {
                $projectInfo = $existingMaterialsData?->project_info
                    ?: ($this->getDefaultMaterialsStructure($taskId)['projectInfo'] ?? []);

                $projectInfo['quoteImportedFrom'] = array_merge($preview['source'], [
                    'importedAt' => now()->toISOString(),
                    'elementCount' => count($elements),
                ]);
                $projectInfo['approval_status'] = $this->defaultMaterialsApprovalStatus('System: Reset due to approved quote import');

                $materialsData = TaskMaterialsData::updateOrCreate(
                    ['enquiry_task_id' => $taskId],
                    [
                        'project_info' => $projectInfo,
                        'updated_at' => now()
                    ]
                );

                $this->replaceMaterialElements($materialsData, $elements);

                return $materialsData->fresh(['elements.materials']);
            });

            try {
                $this->internalCreateVersion(
                    $taskId,
                    'Imported from Approved Quote',
                    'System: Materials generated from approved quote snapshot.',
                    false
                );
            } catch (\Exception $e) {
                \Log::warning('Materials import succeeded but version snapshot failed', [
                    'taskId' => $taskId,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData),
                'source' => $preview['source'],
                'message' => 'Materials imported from approved quote snapshot successfully'
            ]);
        } catch (\RuntimeException $e) {
            $status = $this->clientErrorStatus($e);

            if ($status) {
                return response()->json(['message' => $e->getMessage()], $status);
            }

            throw $e;
        } catch (\Exception $e) {
            \Log::error('Failed to import approved quote materials', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to import approved quote materials',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
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
        $this->authorizeMaterialsMutation($taskId);

        $validator = Validator::make($request->all(), [
            'projectInfo' => 'required|array',
            'projectElements' => 'required|array',
            'projectElements.*.id' => 'required|string',
            'projectElements.*.elementType' => 'required|string|max:500',
            'projectElements.*.name' => 'nullable|string|max:500',
            'projectElements.*.category' => 'required|in:production,hire,outsourced',
            'projectElements.*.requiredQuantity' => 'sometimes|numeric|min:0.0001',
            'projectElements.*.unitOfMeasurement' => 'sometimes|string|max:100',
            // An element with no BOM yet is a legitimate work-in-progress: quote
            // import creates one shell element per quote line with 'materials' => [],
            // and hire/outsourced elements never get a raw-material BOM at all.
            // 'required' would reject an empty array (count($value) < 1), making a
            // partially-specified list unsaveable. Completeness is enforced where it
            // belongs — approveMaterials(), which checks only included production
            // elements. Plain saves stay recoverable.
            'projectElements.*.materials' => 'nullable|array',
            'projectElements.*.materials.*.description' => 'required|string',
            'projectElements.*.materials.*.unitOfMeasurement' => 'required|string',
            'projectElements.*.materials.*.quantity' => 'required|numeric|min:0',
            'availableElements' => 'sometimes|array',
            'editReason' => 'nullable|string',
            'sourceUpdatedAt' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // NOTE: Saving no longer requires a reason, even after a base version exists.
        // The reason-for-edit gate now lives on the Project Officer's re-approval
        // instead (see approveMaterials()) — see materialsChangedSinceVersion().
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();

        try {
            // Use database transactions for data integrity
            \DB::beginTransaction();

            // Get existing materials data to compare for changes
            $existingMaterialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->lockForUpdate()->first();
            if ($existingMaterialsData && $request->filled('sourceUpdatedAt')) {
                $clientVersion = \Carbon\Carbon::parse($request->input('sourceUpdatedAt'));
                if (! $existingMaterialsData->updated_at->equalTo($clientVersion)) {
                    \DB::rollBack();
                    return response()->json([
                        'message' => 'This materials list changed after you opened it. Reload to review the newer list before saving; your browser draft is still available.',
                        'code' => 'MATERIALS_VERSION_CONFLICT',
                        'currentUpdatedAt' => $existingMaterialsData->updated_at->toISOString(),
                    ], 409);
                }
            }
            $existingProjectInfo = $existingMaterialsData ? $existingMaterialsData->project_info : [];
            $existingApprovalStatus = $existingProjectInfo['approval_status'] ?? null;
            
            // Determine if materials content has actually changed
            $materialsChanged = $this->haveMaterialsChanged($existingMaterialsData, $request->projectElements);
            $changeSummary = $materialsChanged ? $this->generateChangeSummary($existingMaterialsData, $request->projectElements) : [];

            // Reset approval status ONLY if materials have actually changed
            $projectInfo = $request->projectInfo;
            if ($materialsChanged && $existingApprovalStatus) {
                \Log::info('Materials content changed - resetting approval status', ['taskId' => $taskId]);
                $projectInfo['approval_status'] = [
                    'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'System: Reset due to material changes'],
                    'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'System: Reset due to material changes'],
                    'all_approved' => false,
                    'last_approval_at' => null
                ];
            } elseif (!$materialsChanged && $existingApprovalStatus) {
                // Preserve existing approval status if materials haven't changed
                \Log::info('Materials unchanged - preserving approval status', ['taskId' => $taskId]);
                $projectInfo['approval_status'] = $existingApprovalStatus;
                
                // Ensure production key exists for legacy data migration
                if (!isset($projectInfo['approval_status']['production'])) {
                    $projectInfo['approval_status']['production'] = [
                        'approved' => false, 
                        'approved_by' => null, 
                        'approved_by_name' => null, 
                        'approved_at' => null, 
                        'comments' => ''
                    ];
                    $projectInfo['approval_status']['all_approved'] = false; // Reset overall if structure changed
                }
            } else {
                // Initialize approval status for new materials data
                $projectInfo['approval_status'] = [
                    'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'all_approved' => false,
                    'last_approval_at' => null
                ];
            }

            // FORCE LOGIC: Calculate all_approved based on strict dual-approval requirement
            // This ensures backend enforces the rule regardless of frontend state
            $poApproved = $projectInfo['approval_status']['project_officer']['approved'] ?? false;
            $prodApproved = $projectInfo['approval_status']['production']['approved'] ?? false;
            
            // STRICT GATE: Both must be true
            $projectInfo['approval_status']['all_approved'] = ($poApproved && $prodApproved);

            $materialsData = TaskMaterialsData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $projectInfo,
                    'updated_at' => now()
                ]
            );

            // Delete the materials explicitly, then their elements.
            //
            // The create migration declared `onDelete('cascade')` on
            // element_materials.project_element_id, but no such constraint
            // exists in the database, so deleting an element silently orphaned
            // every material under it. Two things then broke: the orphan kept
            // the unique `persistent_id`, which forced a fresh UUID for the same
            // material line on every save — destroying the stable identity that
            // cost lines and stock movements point at — and the orphan itself
            // stayed behind forever. Deleting children first frees the id so the
            // line below can keep it.
            ElementMaterial::whereIn(
                'project_element_id',
                $materialsData->elements()->pluck('id'),
            )->delete();
            $materialsData->elements()->delete();

            $idMapping = [];
            $usedPersistentIds = [];

            // Save new elements and materials
            foreach ($request->projectElements as $index => $elementData) {
                // Log incoming ID for debugging
                \Log::debug("Saving Element [$index]", [
                    'name' => $elementData['name'],
                    'incoming_id' => $elementData['id'] ?? 'NULL'
                ]);

                // Ensure ProjectElement persistent_id is unique and not duplicate in this transaction or globally
                $persistentId = $elementData['persistent_id'] ?? $elementData['persistentId'] ?? null;
                if (empty($persistentId) || in_array($persistentId, $usedPersistentIds) || ProjectElement::where('persistent_id', $persistentId)->exists()) {
                    $persistentId = (string) \Illuminate\Support\Str::uuid();
                }
                $usedPersistentIds[] = $persistentId;

                $element = ProjectElement::create([
                    'task_materials_data_id' => $materialsData->id,
                    'template_id' => $elementData['templateId'] ?? null,
                    'scope_id' => $elementData['scopeId'] ?? null,
                    'element_type' => $elementData['elementType'],
                    'name' => $elementData['name'] ?? null,
                    'persistent_id' => $persistentId,
                    'category' => $elementData['category'],
                    'required_quantity' => $elementData['requiredQuantity'] ?? 1,
                    'unit_of_measurement' => $elementData['unitOfMeasurement'] ?? 'Pcs',
                    'dimensions' => $elementData['dimensions'] ?? [],
                    'is_included' => $elementData['isIncluded'] ?? true,
                    'notes' => $elementData['notes'] ?? null,
                    'source_metadata' => $elementData['sourceMetadata'] ?? $elementData['source_metadata'] ?? null,
                    'sort_order' => $elementData['sortOrder'] ?? 0,
                ]);

                $materialMapping = [];

                foreach (($elementData['materials'] ?? []) as $matIndex => $materialData) {
                    // Ensure ElementMaterial persistent_id is unique and not duplicate
                    $matPersistentId = $materialData['persistent_id'] ?? $materialData['persistentId'] ?? null;
                    if (empty($matPersistentId) || in_array($matPersistentId, $usedPersistentIds) || ElementMaterial::where('persistent_id', $matPersistentId)->exists()) {
                        $matPersistentId = (string) \Illuminate\Support\Str::uuid();
                    }
                    $usedPersistentIds[] = $matPersistentId;

                    $material = ElementMaterial::create([
                        'project_element_id' => $element->id,
                        'library_material_id' => $materialData['libraryMaterialId'] ?? null,
                        'persistent_id' => $matPersistentId,
                        'description' => $materialData['description'],
                        'unit_of_measurement' => $materialData['unitOfMeasurement'],
                        'quantity' => $materialData['quantity'],
                        'unit_cost' => $materialData['unitCost'] ?? null,
                        'is_included' => $materialData['isIncluded'] ?? true,
                        'is_additional' => $materialData['isAdditional'] ?? false,
                        'notes' => $materialData['notes'] ?? null,
                        'source_metadata' => $materialData['sourceMetadata'] ?? $materialData['source_metadata'] ?? null,
                        'sort_order' => $materialData['sortOrder'] ?? 0,
                    ]);

                    $oldMatId = isset($materialData['id']) ? (string)$materialData['id'] : null;
                    if ($oldMatId && !str_starts_with($oldMatId, 'temp_') && !str_starts_with($oldMatId, 'new_')) {
                        $materialMapping[(string)$material->id] = $oldMatId;
                    }
                }

                $oldElemId = isset($elementData['id']) ? (string)$elementData['id'] : null;
                if ($oldElemId && !str_starts_with($oldElemId, 'temp_') && !str_starts_with($oldElemId, 'new_')) {
                    $idMapping[(string)$element->id] = [
                        'old_id' => $oldElemId,
                        'materials' => $materialMapping
                    ];
                }
            }

            \DB::commit();

            // Versioning is no longer created on every save — a reasoned, tracked
            // revision is now only created when the Project Officer re-approves
            // edited materials (see approveMaterials()).

            // The budget follows the list on every save, not on approval. Waiting
            // for two sign-offs meant a single added line stalled the budget, the
            // Material Desk and procurement all at once, and left three copies of
            // the same list disagreeing until somebody re-approved. Announced
            // rather than done here: the listener owns the merge.
            MaterialsListChanged::dispatch($taskId);

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
            // 1. Get all predefined templates with their materials
            $templates = ElementTemplate::where('is_active', true)
                ->with('materials')
                ->orderBy('sort_order')
                ->get();

            // 2. Get all element types to capture custom ones or those without templates
            $elementTypes = \App\Models\ElementType::orderBy('order')
                ->orderBy('display_name')
                ->get();

            // 3. Merge them - Templates take priority as they have material data
            $templateNames = $templates->pluck('name')->toArray();
            
            $unifiedList = $templates->map(function ($template) {
                return [
                    'id' => 'tmpl_' . $template->id,
                    'name' => $template->name,
                    'display_name' => $template->display_name,
                    'displayName' => $template->display_name,
                    'description' => $template->description,
                    'category' => $template->category,
                    'color' => $template->color,
                    'order' => $template->sort_order,
                    'is_predefined' => true,
                    'materials' => $template->materials->map(function ($material) {
                        return [
                            'id' => $material->id,
                            'library_material_id' => $material->library_material_id,
                            'libraryMaterialId' => $material->library_material_id,
                            'description' => $material->description,
                            'unit_of_measurement' => $material->unit_of_measurement,
                            'unitOfMeasurement' => $material->unit_of_measurement,
                            'default_quantity' => $material->default_quantity,
                            'defaultQuantity' => $material->default_quantity,
                            'is_default_included' => $material->is_default_included,
                            'isDefaultIncluded' => $material->is_default_included,
                            'unit_cost' => $material->unit_cost,
                            'unitCost' => $material->unit_cost,
                            'order' => $material->sort_order,
                        ];
                    }),
                    'defaultMaterials' => $template->materials->map(function ($material) {
                        return [
                            'id' => $material->id,
                            'description' => $material->description,
                            'unitOfMeasurement' => $material->unit_of_measurement,
                            'defaultQuantity' => $material->default_quantity,
                            'isDefaultIncluded' => $material->is_default_included,
                            'unitCost' => $material->unit_cost,
                        ];
                    })
                ];
            });

            // Add element types that don't have a template record
            foreach ($elementTypes as $type) {
                if (!in_array($type->name, $templateNames)) {
                    $unifiedList->push([
                        'id' => 'type_' . $type->id,
                        'name' => $type->name,
                        'display_name' => $type->display_name,
                        'displayName' => $type->display_name,
                        'description' => '',
                        'category' => $type->category,
                        'color' => 'blue',
                        'order' => $type->order,
                        'is_predefined' => $type->is_predefined,
                        'materials' => [],
                        'defaultMaterials' => []
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $unifiedList,
                'message' => 'Element templates and types retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve element types',
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
                    'library_material_id' => $materialData['libraryMaterialId'] ?? null,
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unitOfMeasurement'],
                    'default_quantity' => $materialData['defaultQuantity'],
                    'unit_cost' => $materialData['unitCost'] ?? null,
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
                'availableElements' => $this->getElementTemplates()->getData()->data ?? [],
                'sourceUpdatedAt' => $materialsData->updated_at?->toISOString(),
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

    private function buildApprovedQuoteImportPreview(int $taskId, ?array $selectedElementIds = null): array
    {
        $materialsTask = EnquiryTask::with('enquiry.client')->find($taskId);

        if (!$materialsTask) {
            throw $this->clientError('Materials task not found.', 404);
        }

        if ($materialsTask->type !== 'materials') {
            throw $this->clientError('This endpoint can only import into a materials task.', 422);
        }

        [$quoteSnapshot, $source] = $this->resolveApprovedQuoteSnapshot($materialsTask);
        $materials = $this->normalizeQuoteMaterialsForImport($quoteSnapshot, $selectedElementIds);

        if (empty($materials) && $selectedElementIds === null) {
            throw $this->clientError('The approved quote snapshot has no element lines available for Materials preparation.', 422);
        }

        return [
            'id' => (string) ($source['quoteId'] ?? $source['approvalId'] ?? $source['quoteVersionId'] ?? $taskId),
            'materials' => $materials,
            'source' => $source,
            'approvalStatus' => 'approved',
            'approvedBy' => $source['approvedBy'] ?? null,
            'approvalDate' => $source['approvalDate'] ?? null,
            'quoteAmount' => $source['quoteAmount'] ?? null,
        ];
    }

    private function resolveApprovedQuoteSnapshot(EnquiryTask $materialsTask): array
    {
        $quoteTask = EnquiryTask::where('project_enquiry_id', $materialsTask->project_enquiry_id)
            ->where('type', 'quote')
            ->first();

        if (!$quoteTask) {
            throw $this->clientError('No Quote task found for this project.', 404);
        }

        $approval = DB::table('quote_approvals')
            ->where('enquiry_id', $materialsTask->project_enquiry_id)
            ->where('approval_status', 'approved')
            ->whereNotNull('quote_data')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($approval) {
            $quoteData = json_decode((string) $approval->quote_data, true);

            if (!is_array($quoteData)) {
                // Legacy Excel approvals sometimes persisted the literal JSON
                // value `null`. Recover from the server-owned approved baseline
                // rather than asking users to repeat a valid approval decision.
                $approvedQuote = TaskQuoteData::where('enquiry_task_id', $quoteTask->id)->first();
                $approvedVersion = $approvedQuote?->versions()
                    ->where(function ($query) {
                        $query->where('label', 'Baseline Approved')
                            ->orWhere('label', 'like', 'Baseline Approved%');
                    })
                    ->orderByDesc('version_number')
                    ->orderByDesc('id')
                    ->first();

                if ($approvedVersion && is_array($approvedVersion->data)) {
                    $quoteData = $approvedVersion->data;
                } elseif ($approvedQuote && in_array($approvedQuote->approval_status ?? $approvedQuote->status, ['approved'], true)) {
                    $quoteData = $approvedQuote->toArray();
                } else {
                    throw $this->clientError('The approved quote snapshot is unreadable and no approved server baseline is available. Please re-approve the quote.', 409);
                }
            }

            // Excel approvals historically froze the uploaded file and amount
            // while quote_data.materials remained empty. Attach the extraction
            // belonging to the still-approved upload so Materials can consume
            // the exact revision without manufacturing rows from the file later.
            $uploadedQuote = TaskQuoteData::where('enquiry_task_id', $quoteTask->id)->first();
            if ($uploadedQuote?->quote_mode === 'excel_upload' && !empty($uploadedQuote->excel_quote_extraction['elements'])) {
                $quoteData['quote_mode'] = 'excel_upload';
                $quoteData['excel_quote_extraction'] = $uploadedQuote->excel_quote_extraction;
            }

            return [
                $quoteData,
                [
                    'snapshotSource' => 'quote_approvals',
                    'approvalId' => $approval->id,
                    'approvalTaskId' => $approval->task_id,
                    'quoteTaskId' => $quoteTask->id,
                    'quoteId' => $quoteData['id'] ?? null,
                    'approvedBy' => $approval->approved_by,
                    'approvalDate' => $approval->approval_date,
                    'quoteAmount' => (float) $approval->quote_amount,
                    'snapshotCreatedAt' => $approval->updated_at,
                ]
            ];
        }

        $taskQuoteData = TaskQuoteData::where('enquiry_task_id', $quoteTask->id)->first();
        $isApproved = in_array($taskQuoteData?->approval_status ?? $taskQuoteData?->status, ['approved'], true);
        $approvedVersion = $taskQuoteData
            ? $taskQuoteData->versions()
                ->where(function ($query) {
                    $query->where('label', 'Baseline Approved')
                        ->orWhere('label', 'like', '%Approved%');
                })
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->first()
            : null;

        if ($isApproved && $approvedVersion && is_array($approvedVersion->data)) {
            return [
                $approvedVersion->data,
                [
                    'snapshotSource' => 'quote_versions',
                    'quoteVersionId' => $approvedVersion->id,
                    'quoteVersionNumber' => $approvedVersion->version_number,
                    'quoteTaskId' => $quoteTask->id,
                    'quoteId' => $taskQuoteData->id,
                    'approvedBy' => $taskQuoteData->approved_by,
                    'approvalDate' => optional($taskQuoteData->approval_date)->format('Y-m-d'),
                    'quoteAmount' => $taskQuoteData->quote_amount !== null ? (float) $taskQuoteData->quote_amount : null,
                    'snapshotCreatedAt' => optional($approvedVersion->created_at)->toISOString(),
                ]
            ];
        }

        throw $this->clientError('No approved quote snapshot found. Complete the Quote Approval task before generating the materials list.', 409);
    }

    private function normalizeQuoteMaterialsForImport(array $quoteSnapshot, ?array $selectedElementIds = null): array
    {
        $quoteElements = $quoteSnapshot['materials'] ?? [];
        $extractedElements = $quoteSnapshot['excel_quote_extraction']['elements']
            ?? $quoteSnapshot['excelQuoteExtraction']['elements']
            ?? [];
        // Compatibility for uploads extracted before quote rows were correctly
        // modelled as elements. Those snapshots grouped rows under a sheet and
        // placed each commercial line in `materials`; flatten them without
        // requiring a new upload or approval.
        if (!empty($extractedElements)) {
            $correctedElements = [];
            foreach ($extractedElements as $legacyElement) {
                $legacyLines = is_array($legacyElement) ? ($legacyElement['materials'] ?? []) : [];
                $alreadyElement = is_array($legacyElement) && array_key_exists('quotedQuantity', $legacyElement);
                if ($alreadyElement || empty($legacyLines)) {
                    $correctedElements[] = $legacyElement;
                    continue;
                }
                foreach ($legacyLines as $lineIndex => $line) {
                    if (!is_array($line) || trim((string) ($line['description'] ?? '')) === '') continue;
                    $correctedElements[] = [
                        'id' => (string) ($line['id'] ?? ($legacyElement['id'] . '-line-' . $lineIndex)),
                        'sourceKey' => $line['sourceKey'] ?? null,
                        'sourceSheet' => $line['sourceSheet'] ?? null,
                        'sourceRow' => $line['sourceRow'] ?? null,
                        'section' => $legacyElement['name'] ?? null,
                        'elementType' => $line['description'],
                        'name' => $line['description'],
                        'category' => $legacyElement['category'] ?? 'production',
                        'quotedQuantity' => $line['quantity'] ?? 0,
                        'quotedUnit' => $line['unitOfMeasurement'] ?? 'Pcs',
                        'quotedUnitPrice' => $line['quotedUnitPrice'] ?? null,
                        'quotedLineTotal' => $line['quotedLineTotal'] ?? null,
                        'isIncluded' => $line['isIncluded'] ?? true,
                        'isVisible' => $line['isVisible'] ?? true,
                        'materials' => [],
                    ];
                }
            }
            $extractedElements = $correctedElements;
        }
        $isExcelSnapshot = ($quoteSnapshot['quote_mode'] ?? $quoteSnapshot['quoteMode'] ?? null) === 'excel_upload';
        $hasStructuredQuoteLines = collect($quoteElements)->contains(
            fn ($element) => is_array($element) && !empty($element['materials'])
        );

        if (!empty($extractedElements) && ($isExcelSnapshot || !$hasStructuredQuoteLines)) {
            $quoteElements = $extractedElements;
        }

        if (!is_array($quoteElements)) {
            return [];
        }

        $selected = $selectedElementIds === null
            ? null
            : array_map('strval', $selectedElementIds);

        $elements = [];

        foreach ($quoteElements as $index => $quoteElement) {
            if (!is_array($quoteElement)) {
                continue;
            }

            $sourceId = (string) ($quoteElement['id'] ?? $quoteElement['persistent_id'] ?? $quoteElement['scopeId'] ?? $quoteElement['scope_id'] ?? 'quote_element_' . ($index + 1));

            if ($selected !== null && !in_array($sourceId, $selected, true)) {
                continue;
            }

            if (!$this->importFlag($quoteElement['isVisible'] ?? $quoteElement['is_visible'] ?? true)) {
                continue;
            }

            $materials = $this->normalizeQuoteMaterialLines($quoteElement['materials'] ?? [], $sourceId);

            $isExtractedElement = isset($quoteElement['sourceKey']) || array_key_exists('quotedQuantity', $quoteElement);
            if (empty($materials) && !$isExtractedElement) {
                continue;
            }

            $templateId = $quoteElement['templateId'] ?? $quoteElement['template_id'] ?? null;
            $category = $this->normalizeMaterialCategory($quoteElement['category'] ?? $templateId);
            $elementType = trim((string) ($quoteElement['elementType'] ?? $quoteElement['element_type'] ?? $templateId ?? $category));

            $elements[] = [
                'id' => $sourceId,
                'templateId' => $templateId,
                'scopeId' => $quoteElement['scopeId'] ?? $quoteElement['scope_id'] ?? null,
                'elementType' => $elementType !== '' ? $elementType : 'General',
                'name' => trim((string) ($quoteElement['name'] ?? $quoteElement['description'] ?? 'Quote Element ' . ($index + 1))),
                'category' => $category,
                'requiredQuantity' => max(0.0001, $this->numberValue($quoteElement['quotedQuantity'] ?? 1)),
                'unitOfMeasurement' => trim((string) ($quoteElement['quotedUnit'] ?? 'Pcs')) ?: 'Pcs',
                'dimensions' => $quoteElement['dimensions'] ?? ['length' => '', 'width' => '', 'height' => ''],
                'isIncluded' => $this->importFlag($quoteElement['isIncluded'] ?? $quoteElement['is_included'] ?? true),
                'notes' => $quoteElement['description'] ?? null,
                'sourceMetadata' => [
                    'source' => 'approved_quote',
                    'sourceKey' => $quoteElement['sourceKey'] ?? $sourceId,
                    'sourceSheet' => $quoteElement['sourceSheet'] ?? null,
                    'sourceRow' => $quoteElement['sourceRow'] ?? null,
                    'section' => $quoteElement['section'] ?? null,
                    'quotedQuantity' => $this->numberValue($quoteElement['quotedQuantity'] ?? 0),
                    'quotedUnit' => $quoteElement['quotedUnit'] ?? 'Pcs',
                    'quotedUnitPrice' => $this->optionalNumber($quoteElement['quotedUnitPrice'] ?? null),
                    'quotedLineTotal' => $this->optionalNumber($quoteElement['quotedLineTotal'] ?? null),
                    'itemCode' => $quoteElement['itemCode'] ?? null,
                ],
                'sortOrder' => $index,
                'finalTotal' => $this->numberValue($quoteElement['finalTotal'] ?? $quoteElement['final_total'] ?? 0),
                'materials' => $materials,
            ];
        }

        return $elements;
    }

    private function normalizeQuoteMaterialLines(array $quoteMaterials, string $sourceElementId): array
    {
        $materials = [];

        foreach ($quoteMaterials as $index => $quoteMaterial) {
            if (!is_array($quoteMaterial)) {
                continue;
            }

            if (!$this->importFlag($quoteMaterial['isVisible'] ?? $quoteMaterial['is_visible'] ?? true)) {
                continue;
            }

            $description = trim((string) ($quoteMaterial['description'] ?? $quoteMaterial['name'] ?? ''));

            if ($description === '') {
                continue;
            }

            $unitCost = $this->optionalNumber(
                $quoteMaterial['unitCost']
                ?? $quoteMaterial['unit_cost']
                ?? $quoteMaterial['baseCost']
                ?? $quoteMaterial['base_cost']
                ?? null
            );

            $materials[] = [
                'id' => (string) ($quoteMaterial['id'] ?? $sourceElementId . '_material_' . ($index + 1)),
                'libraryMaterialId' => $quoteMaterial['libraryMaterialId'] ?? $quoteMaterial['library_material_id'] ?? null,
                'description' => $description,
                'unitOfMeasurement' => trim((string) ($quoteMaterial['unitOfMeasurement'] ?? $quoteMaterial['unit_of_measurement'] ?? $quoteMaterial['unit'] ?? 'Pcs')),
                'quantity' => max(0, $this->numberValue($quoteMaterial['quantity'] ?? 0)),
                'unitCost' => $unitCost,
                'isIncluded' => $this->importFlag($quoteMaterial['isIncluded'] ?? $quoteMaterial['is_included'] ?? true),
                'isAdditional' => false,
                'notes' => null,
                'sourceMetadata' => [
                    'source' => 'approved_quote',
                    'sourceKey' => $quoteMaterial['sourceKey'] ?? ($sourceElementId . '_material_' . ($index + 1)),
                    'sourceSheet' => $quoteMaterial['sourceSheet'] ?? null,
                    'sourceRow' => $quoteMaterial['sourceRow'] ?? null,
                    'quotedUnitPrice' => $this->optionalNumber($quoteMaterial['quotedUnitPrice'] ?? null),
                    'quotedLineTotal' => $this->optionalNumber($quoteMaterial['quotedLineTotal'] ?? null),
                    'matchStatus' => $quoteMaterial['matchStatus'] ?? 'unmatched',
                ],
                'sortOrder' => $index,
            ];
        }

        return $materials;
    }

    private function replaceMaterialElements(TaskMaterialsData $materialsData, array $elements): void
    {
        $materialsData->elements()->delete();

        foreach ($elements as $elementData) {
            $element = ProjectElement::create([
                'task_materials_data_id' => $materialsData->id,
                'template_id' => $elementData['templateId'] ?? null,
                'scope_id' => $elementData['scopeId'] ?? null,
                'element_type' => $elementData['elementType'],
                'name' => $elementData['name'],
                'persistent_id' => (string) Str::uuid(),
                'category' => $elementData['category'],
                'required_quantity' => $elementData['requiredQuantity'] ?? 1,
                'unit_of_measurement' => $elementData['unitOfMeasurement'] ?? 'Pcs',
                'dimensions' => $elementData['dimensions'] ?? [],
                'is_included' => $elementData['isIncluded'] ?? true,
                'notes' => $elementData['notes'] ?? null,
                'source_metadata' => $elementData['sourceMetadata'] ?? $elementData['source_metadata'] ?? null,
                'sort_order' => $elementData['sortOrder'] ?? 0,
            ]);

            foreach (($elementData['materials'] ?? []) as $materialData) {
                ElementMaterial::create([
                    'project_element_id' => $element->id,
                    'library_material_id' => $materialData['libraryMaterialId'] ?? null,
                    'persistent_id' => (string) Str::uuid(),
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unitOfMeasurement'],
                    'quantity' => $materialData['quantity'],
                    'unit_cost' => $materialData['unitCost'] ?? null,
                    'is_included' => $materialData['isIncluded'] ?? true,
                    'is_additional' => $materialData['isAdditional'] ?? false,
                    'notes' => $materialData['notes'] ?? null,
                    'source_metadata' => $materialData['sourceMetadata'] ?? $materialData['source_metadata'] ?? null,
                    'sort_order' => $materialData['sortOrder'] ?? 0,
                ]);
            }
        }
    }

    private function defaultMaterialsApprovalStatus(string $comments = ''): array
    {
        return [
            'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => $comments],
            'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => $comments],
            'all_approved' => false,
            'last_approval_at' => null
        ];
    }

    private function normalizeMaterialCategory(mixed $value): string
    {
        $category = strtolower((string) ($value ?? 'production'));

        return in_array($category, ['production', 'hire', 'outsourced'], true)
            ? $category
            : 'production';
    }

    private function importFlag(mixed $value, bool $default = true): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private function optionalNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->numberValue($value, 0.0);
    }

    private function numberValue(mixed $value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = preg_replace('/[^0-9.\-]/', '', $value);

            if ($normalized !== '' && is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return $default;
    }

    private function clientError(string $message, int $status): \RuntimeException
    {
        return new \RuntimeException($message, $status);
    }

    private function clientErrorStatus(\RuntimeException $exception): ?int
    {
        $status = $exception->getCode();

        return $status >= 400 && $status < 500 ? $status : null;
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
                        'scopeId' => $element->scope_id,
                        'elementType' => $element->element_type,
                        'name' => $element->name,
                        'persistent_id' => $element->persistent_id,
                        'category' => $element->category,
                        'requiredQuantity' => (float) $element->required_quantity,
                        'unitOfMeasurement' => $element->unit_of_measurement,
                        'dimensions' => $element->dimensions ?? ['length' => '', 'width' => '', 'height' => ''],
                        'isIncluded' => (bool) $element->is_included,
                        'materials' => $element->materials->map(function ($material) {
                            return [
                                'id' => (string) $material->id,
                                'persistent_id' => $material->persistent_id,
                                'libraryMaterialId' => $material->library_material_id,
                                'description' => $material->description,
                                'unitOfMeasurement' => $material->unit_of_measurement,
                                'quantity' => (float) $material->quantity,
                                'unitCost' => $material->unit_cost !== null ? (float) $material->unit_cost : null,
                                'isIncluded' => (bool) $material->is_included,
                                'isAdditional' => (bool) $material->is_additional,
                                'notes' => $material->notes,
                                'sourceMetadata' => $material->source_metadata,
                                'createdAt' => $material->created_at?->toISOString(),
                                'updatedAt' => $material->updated_at?->toISOString(),
                            ];
                        })->toArray(),
                        'notes' => $element->notes,
                        'sourceMetadata' => $element->source_metadata,
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
     * @param string $department Department name (design, production, project_officer)
     */
    public function approveMaterials(Request $request, int $taskId, string $department): JsonResponse
    {
        $task = EnquiryTask::findOrFail($taskId);
        abort_unless($task->type === 'materials', 422, 'This action is only valid for a materials task.');

        $user = auth()->user();
        $userRoles = $user->roles->pluck('name')->toArray();
        $isSuperAdmin = in_array('Super Admin', $userRoles);

        // Department-specific authorization
        if ($department === 'project_officer') {
            if (!$isSuperAdmin && !$user->hasRole(['Project Officer', 'Project Manager', 'Admin'])) {
                return response()->json([
                    'message' => 'Unauthorized: Only Project Officers, Project Managers, or Admins can approve for Project Officer department.'
                ], 403);
            }
        } elseif ($department === 'production') {
            if (!$isSuperAdmin && !$user->hasRole(['Production', 'Production Manager', 'Admin'])) {
                return response()->json([
                    'message' => 'Unauthorized: Only Production staff or Admins can approve for Production department.'
                ], 403);
            }
        }

        $validator = Validator::make(['department' => $department], [
            'department' => 'required|in:project_officer,production'  // Allow BOTH departments
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid department',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check Design Gate before proceeding
            $gate = $this->checkDesignApprovalGate($taskId);
            if ($gate['is_gated']) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Unauthorized: ' . $gate['message'],
                    'designGate' => $gate
                ], 403);
            }

            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->lockForUpdate()->first();

            if (!$materialsData) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Materials data not found for this task'
                ], 404);
            }

            // Imported quote elements are valid planning shells, but an
            // included in-house production element cannot be approved until
            // its execution BOM has at least one active line. Hire and
            // outsourced elements may legitimately have no raw-material BOM.
            $materialsData->loadMissing('elements.materials');
            $incompleteProductionElements = $materialsData->elements
                ->filter(fn ($element) => $element->is_included && $element->category === 'production')
                ->filter(fn ($element) => !$element->materials->contains(fn ($material) => $material->is_included))
                ->values();

            if ($incompleteProductionElements->isNotEmpty()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Complete the BOM for all included production elements before approval.',
                    'code' => 'MATERIALS_BOM_INCOMPLETE',
                    'incompleteElements' => $incompleteProductionElements->map(fn ($element) => [
                        'id' => (string) $element->id,
                        'name' => $element->name ?: $element->element_type,
                    ])->all(),
                ], 422);
            }

            // Project Officer re-approval gate: if a base snapshot exists and the
            // materials have actually changed since it was captured, the PO must
            // explain why before their approval is recorded. This is the only
            // point in the materials workflow that requires a reason — plain
            // saves are always free.
            $baseVersion = $materialsData->versions()->where('is_base', true)->latest('version_number')->first();
            $changedSinceBase = $baseVersion && $this->materialsChangedSinceVersion($materialsData, $baseVersion);

            if (
                $department === 'project_officer'
                && $changedSinceBase
                && !$request->filled('editReason')
            ) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'editReason' => ['Materials have changed since they were first approved. Please explain why before approving again.']
                    ]
                ], 422);
            }

            // Get current approval status from project_info
            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? [
                'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
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
                'comments' => $request->input('comments', ''),
                'edit_reason' => $request->input('editReason'),
            ];

            // STRICT GATE: BOTH departments must approve for all_approved to be true
            $poApproved = $approvalStatus['project_officer']['approved'] ?? false;
            $prodApproved = $approvalStatus['production']['approved'] ?? false;
            $allApproved = ($poApproved && $prodApproved);  // BOTH must be true

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

                // Saving already announced the change and the budget already
                // followed it, so approval has nothing left to push. Re-announced
                // only because the listener is idempotent and this closes the gap
                // if a queued sync failed earlier.
                DB::afterCommit(fn () => MaterialsListChanged::dispatch($taskId));
            }

            // NEW: Handle Base Snapshot on First Approval
            $this->handleBaseSnapshotOnApproval($taskId);

            // A re-approval that needed a reason is a revision, so record one.
            // The reason was being written into the approval JSON and nowhere
            // else: the audit trail said somebody explained a change, without
            // holding the list they were explaining. The snapshot is taken after
            // the approval is stored, so the version and the approval it belongs
            // to describe the same moment.
            if ($department === 'project_officer' && $changedSinceBase && $request->filled('editReason')) {
                try {
                    $this->internalCreateVersion(
                        $taskId,
                        'Revision - Re-approved after edits',
                        $request->input('editReason'),
                        false,
                    );
                } catch (\Exception $e) {
                    // Never fail an approval over its own audit copy: the reason
                    // is already recorded on the approval, and a missing snapshot
                    // is visible in the version list.
                    \Log::warning('Approval recorded but its revision snapshot failed', [
                        'taskId' => $taskId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Complete only after both approvals and all final synchronization
            // work have succeeded. A single approval must never close the task.
            if ($allApproved) {
                $materialsTask = $materialsData->task()->firstOrFail();
                app(\App\Modules\Projects\Actions\AutoSyncTaskStateAction::class)->execute($materialsTask);
            }

            DB::commit();

            return response()->json([
                'message' => ucfirst($department) . ' approval recorded successfully',
                'approval_status' => $approvalStatus,
                'task_status' => $materialsData->task()->value('status'),
                // Null until both departments have approved. Once they have, the
                // caller can tell an actual budget update apart from a silent
                // no-op (no budget task yet, sync error) instead of reading
                // "approval recorded successfully" as "the budget moved".
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
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
                        'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'all_approved' => false,
                        'last_approval_at' => null
                    ],
                    'pending' => ['project_officer']
                ]);
            }

            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? [
                'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                'all_approved' => false,
                'last_approval_at' => null
            ];

            // Calculate pending departments
            $pending = [];
            foreach (['project_officer', 'production'] as $dept) {
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
     * Generate a structured list of changes between existing data and new elements
     */
    private function generateChangeSummary(?TaskMaterialsData $existingData, array $newProjectElements): array
    {
        if (!$existingData) return [['type' => 'addition', 'message' => 'Initial material list creation.']];

        $existingData->load('elements.materials');
        $existingElements = $existingData->elements;
        $changes = [];

        // Normalize existing for lookups
        $existingMap = [];
        foreach ($existingElements as $element) {
            $key = $element->element_type . ' : ' . ($element->name ?? '');
            $existingMap[$key] = $element;
        }

        // Track new/updated
        $newKeys = [];
        foreach ($newProjectElements as $element) {
            $key = $element['elementType'] . ' : ' . ($element['name'] ?? '');
            $newKeys[] = $key;

            if (!isset($existingMap[$key])) {
                $count = count($element['materials'] ?? []);
                $changes[] = ['type' => 'addition', 'message' => "Added Element: " . ($element['name'] ?? 'Unnamed') . " ({$count} materials)"];
            } else {
                $oldElement = $existingMap[$key];
                $oldMaterialsMap = [];
                foreach ($oldElement->materials as $m) {
                    $oldMaterialsMap[$m->description] = $m;
                }

                $newMaterials = $element['materials'] ?? [];
                
                foreach ($newMaterials as $m) {
                    $desc = $m['description'];
                    if (!isset($oldMaterialsMap[$desc])) {
                        $changes[] = ['type' => 'addition', 'message' => "Element '" . ($element['name'] ?? 'Unnamed') . "': Added material '{$desc}'"];
                    } else {
                        $oldM = $oldMaterialsMap[$desc];
                        if ((float)$oldM->quantity !== (float)$m['quantity']) {
                            $changes[] = [
                                'type' => 'modification', 
                                'message' => "Element '" . ($element['name'] ?? 'Unnamed') . "': Changed '{$desc}' qty: {$oldM->quantity} -> {$m['quantity']}"
                            ];
                        }
                    }
                }

                // Check for deletions
                $currentMaterialNames = collect($newMaterials)->pluck('description')->toArray();
                foreach (array_keys($oldMaterialsMap) as $oldMName) {
                    if (!in_array($oldMName, $currentMaterialNames)) {
                        $changes[] = ['type' => 'removal', 'message' => "Element '" . ($element['name'] ?? 'Unnamed') . "': Removed material '{$oldMName}'"];
                    }
                }
            }
        }

        // Check for deleted elements
        foreach ($existingMap as $key => $element) {
            if (!in_array($key, $newKeys)) {
                $changes[] = ['type' => 'removal', 'message' => "Removed Element: " . ($element->name ?? 'Unnamed')];
            }
        }

        return $changes;
    }

    /**
     * Check if materials content has actually changed
     */
    private function haveMaterialsChanged(?TaskMaterialsData $existingData, array $newProjectElements): bool
    {
        $summary = $this->generateChangeSummary($existingData, $newProjectElements);
        return !empty($summary) && $summary[0]['message'] !== "Initial material list creation.";
    }

    /**
     * Create a new material list version
     * Captures a complete snapshot of the current material list state
     */
    public function createMaterialVersion(Request $request, int $taskId): JsonResponse
    {
        try {
            $version = $this->internalCreateVersion(
                $taskId, 
                $request->input('label'), 
                $request->input('reason'), 
                $request->input('is_base', false)
            );

            return response()->json([
                'message' => 'Version created successfully',
                'data' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'label' => $version->label,
                    'is_base' => $version->is_base,
                    'created_at' => $version->created_at,
                    'created_by_name' => $version->creator->name ?? 'Unknown'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Internal helper to handle version creation
     */
    private function internalCreateVersion(int $taskId, ?string $label = null, ?string $reason = null, bool $isBase = false, $changeLog = null): \App\Models\MaterialVersion
    {
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)
            ->with(['elements.materials'])
            ->first();

        if (!$materialsData) {
            throw new \Exception('Materials data not found');
        }

        // Calculate next version number
        $latestVersion = $materialsData->versions()->max('version_number') ?? 0;
        $newVersionNumber = $latestVersion + 1;

        // Build complete snapshot
        $snapshot = [
            'project_info' => $materialsData->project_info,
            'elements' => $materialsData->elements->map(function ($element) {
                return [
                    'id' => $element->id,
                    'template_id' => $element->template_id,
                    'element_type' => $element->element_type,
                    'name' => $element->name,
                    'category' => $element->category,
                    'dimensions' => $element->dimensions,
                    'is_included' => $element->is_included,
                    'sort_order' => $element->sort_order,
                    'notes' => $element->notes,
                    'materials' => $element->materials->map(function ($material) {
                        return [
                            'id' => $material->id,
                            'description' => $material->description,
                            'unit_of_measurement' => $material->unit_of_measurement,
                            'quantity' => $material->quantity,
                            'is_included' => $material->is_included,
                            'is_additional' => $material->is_additional ?? false,
                            'notes' => $material->notes,
                            'sort_order' => $material->sort_order,
                        ];
                    })->toArray()
                ];
            })->toArray()
        ];

        // Create version
        return $materialsData->versions()->create([
            'version_number' => $newVersionNumber,
            'is_base' => $isBase,
            'label' => $label ?? ($isBase ? 'Base Materials' : 'Version ' . $newVersionNumber),
            'reason' => $reason,
            'change_log' => $changeLog,
            'data' => $snapshot,
            'created_by' => auth()->id() ?? 1,
            'source_updated_at' => $materialsData->updated_at,
        ]);
    }

    /**
     * Automatically create a base snapshot if it's the first approval
     */
    private function handleBaseSnapshotOnApproval(int $taskId): void
    {
        $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();
        if (!$materialsData) return;

        // Check if an approval already exists (at least one)
        $approvalStatus = $materialsData->project_info['approval_status'] ?? [];
        $poApproved = $approvalStatus['project_officer']['approved'] ?? false;
        $prodApproved = $approvalStatus['production']['approved'] ?? false;

        // Check if base version already exists
        $baseExists = $materialsData->versions()->where('is_base', true)->exists();

        if (!$baseExists && ($poApproved || $prodApproved)) {
            \Log::info("First approval received. Creating base materials snapshot.", ['taskId' => $taskId]);
            $this->internalCreateVersion(
                $taskId, 
                'Base Materials (Snapshot on First Approval)', 
                'Automatically created upon initial departmental approval.',
                true
            );
        }
    }

    /**
     * Compare the materials data currently on a task against a specific stored
     * version's snapshot. Used to decide whether the Project Officer's
     * re-approval needs a reason (i.e. whether anything actually changed since
     * that version was captured).
     */
    private function materialsChangedSinceVersion(TaskMaterialsData $materialsData, MaterialVersion $version): bool
    {
        $materialsData->loadMissing('elements.materials');

        $normalize = function (string $elementType, ?string $name, $materials) {
            $key = $elementType . ' : ' . ($name ?? '');
            $materialsMap = [];
            foreach ($materials as $material) {
                $description = is_array($material) ? ($material['description'] ?? '') : $material->description;
                $quantity = is_array($material) ? ($material['quantity'] ?? 0) : $material->quantity;
                $materialsMap[$description] = round((float) $quantity, 4);
            }
            ksort($materialsMap);
            return [$key => $materialsMap];
        };

        $current = [];
        foreach ($materialsData->elements as $element) {
            $current += $normalize($element->element_type, $element->name, $element->materials);
        }
        ksort($current);

        $base = [];
        foreach (($version->data['elements'] ?? []) as $element) {
            $base += $normalize($element['element_type'] ?? '', $element['name'] ?? null, $element['materials'] ?? []);
        }
        ksort($base);

        return $current !== $base;
    }

    /**
     * Get all versions for a material list
     */
    public function getMaterialVersions(int $taskId): JsonResponse
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();

            if (!$materialsData) {
                return response()->json(['data' => []]);
            }

            $versions = $materialsData->versions()
                ->with('creator')
                ->orderBy('version_number', 'desc')
                ->get()
                ->map(function ($version) {
                    return [
                        'id' => $version->id,
                        'version_number' => $version->version_number,
                        'label' => $version->label,
                        'is_base' => (bool)$version->is_base,
                        'reason' => $version->reason,
                        'change_log' => $version->change_log,
                        'created_at' => $version->created_at,
                        'created_by_name' => $version->creator->name ?? 'Unknown',
                        'source_updated_at' => $version->source_updated_at,
                    ];
                });

            return response()->json(['data' => $versions]);

        } catch (\Exception $e) {
            \Log::error('Failed to get material versions', [
                'taskId' => $taskId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve versions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Restore a material list to a specific version
     * Implements conflict detection and approval reset per success criteria
     */
    public function restoreMaterialVersion(int $taskId, int $versionId): JsonResponse
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();
            
            if (!$materialsData) {
                return response()->json(['message' => 'Materials data not found'], 404);
            }

            $version = \App\Models\MaterialVersion::find($versionId);
            
            if (!$version) {
                return response()->json(['message' => 'Version not found'], 404);
            }

            // Validate version belongs to this material list
            if ($version->task_materials_data_id !== $materialsData->id) {
                return response()->json(['message' => 'Invalid version for this material list'], 400);
            }

            // CONFLICT DETECTION: Check if source data changed since version was created
            $hasChanged = false;
            $changeWarning = null;
            if ($version->source_updated_at && $materialsData->updated_at) {
                if ($materialsData->updated_at->gt($version->source_updated_at)) {
                    $hasChanged = true;
                    $changeWarning = 'Warning: Materials have been modified since this version was created. Restoring will overwrite current changes.';
                    \Log::warning('Restoring version with newer source data', [
                        'version_updated' => $version->source_updated_at,
                        'current_updated' => $materialsData->updated_at
                    ]);
                }
            }

            $restoredData = $version->data;

            // Delete all existing elements and materials (cascade will handle materials)
            \DB::transaction(function () use ($materialsData, $restoredData) {
                // Delete existing elements (materials will cascade delete)
                $materialsData->elements()->delete();

                // Recreate elements from snapshot
                foreach ($restoredData['elements'] as $elementData) {
                    $element = $materialsData->elements()->create([
                        'template_id' => $elementData['template_id'] ?? null,
                        'element_type' => $elementData['element_type'],
                        'name' => $elementData['name'],
                        'category' => $elementData['category'],
                        'dimensions' => $elementData['dimensions'] ?? null,
                        'is_included' => $elementData['is_included'] ?? true,
                        'sort_order' => $elementData['sort_order'] ?? 0,
                        'notes' => $elementData['notes'] ?? null,
                    ]);

                    // Recreate materials for this element
                    foreach (($elementData['materials'] ?? []) as $materialData) {
                        $element->materials()->create([
                            'description' => $materialData['description'],
                            'unit_of_measurement' => $materialData['unit_of_measurement'],
                            'quantity' => $materialData['quantity'],
                            'is_included' => $materialData['is_included'] ?? true,
                            'is_additional' => $materialData['is_additional'] ?? false,
                            'notes' => $materialData['notes'] ?? null,
                            'sort_order' => $materialData['sort_order'] ?? 0,
                        ]);
                    }
                }

                // Update project_info but RESET APPROVAL STATUS (Success Criteria #6)
                $projectInfo = $restoredData['project_info'];
                
                // Reset all approvals to draft
                $projectInfo['approval_status'] = [
                    'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                    'all_approved' => false,
                    'last_approval_at' => null,
                    'restored_from_version' => $elementData['version_number'] ?? null,
                    'restored_at' => now()->toISOString(),
                    'restored_by' => auth()->user()->name ?? 'System'
                ];

                $materialsData->update([
                    'project_info' => $projectInfo
                ]);
            });

            \Log::info('Material version restored successfully', [
                'task_id' => $taskId,
                'version_id' => $versionId,
                'version_number' => $version->version_number,
                'had_conflicts' => $hasChanged
            ]);

            // NEW: Create a tracking version for the restoation event itself
            // This ensures the audit trail reflects the rollback in the versions table
            $this->internalCreateVersion(
                $taskId, 
                'Revision - Restored from v' . $version->version_number, 
                'System: Restored to state from Snapshot #' . $version->version_number . '. Approvals reset.',
                false
            );

            // Reload data to return fresh snapshot
            $materialsData->load('elements.materials');

            return response()->json([
                'message' => 'Materials restored to version ' . $version->version_number . ($hasChanged ? ' (with conflicts)' : ''),
                'warning' => $changeWarning,
                'data' => $this->formatMaterialsData($materialsData),
                'approvals_reset' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to restore material version', [
                'taskId' => $taskId,
                'versionId' => $versionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to restore version',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete a project element and all its materials
     */
    public function deleteElement(int $taskId, string $elementId): JsonResponse
    {
        // Elements the user added but has not saved yet still carry a
        // client-generated id ("custom-1724...", "scope-3"). Those never reach
        // the database, and an `int` type hint turns them into a 500 TypeError
        // thrown before this try block exists. Answer them honestly instead.
        if (! ctype_digit($elementId)) {
            return response()->json([
                'message' => 'This element has not been saved yet, so there is nothing to delete on the server.',
                'code' => 'ELEMENT_NEVER_SAVED',
            ], 404);
        }

        try {
            // Find the task materials data
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)->first();
            
            if (!$materialsData) {
                return response()->json([
                    'message' => 'Task materials data not found'
                ], 404);
            }

            // Find the element
            $element = ProjectElement::where('task_materials_data_id', $materialsData->id)
                ->where('id', $elementId)
                ->first();

            if (!$element) {
                return response()->json([
                    'message' => 'Element not found'
                ], 404);
            }

            // Check if approvals exist in project_info and reset them if necessary
            $projectInfo = $materialsData->project_info ?? [];
            $approvalStatus = $projectInfo['approval_status'] ?? null;
            $approvalsReset = false;

            if ($approvalStatus) {
                // Check if any department is approved
                $hasApprovals = ($approvalStatus['design']['approved'] ?? false) ||
                               ($approvalStatus['production']['approved'] ?? false) ||
                               ($approvalStatus['project_officer']['approved'] ?? false);

                if ($hasApprovals) {
                    // Reset approvals
                    $projectInfo['approval_status'] = [
                        'design' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'production' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'project_officer' => ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => ''],
                        'all_approved' => false,
                        'last_approval_at' => null
                    ];
                    
                    $materialsData->project_info = $projectInfo;
                    $materialsData->save();
                    $approvalsReset = true;
                }
            }

            // The element_materials cascade is declared in the create migration but
            // is absent from the live schema, so deleting the element alone strands
            // its material rows. Delete them explicitly — the same reason
            // saveMaterialsData() clears them by project_element_id rather than
            // trusting the constraint.
            $element->materials()->delete();
            $element->delete();

            return response()->json([
                'message' => 'Element deleted successfully',
                'approvals_reset' => $approvalsReset
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error deleting element', [
                'task_id' => $taskId,
                'element_id' => $elementId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete element',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Download Excel template for materials upload
     *
     * @OA\Get(
     *     path="/api/projects/tasks/{taskId}/materials/template/download",
     *     tags={"Materials"},
     *     summary="Download Excel template for materials upload",
     *     description="Generates and downloads an Excel template with instructions, project info, and data entry sheet",
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
     *         description="Template downloaded successfully",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to generate template",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
    public function downloadTemplate(int $taskId)
    {
        try {
            $export = new \App\Modules\Projects\Exports\MaterialsTemplateExport($taskId);
            
            // Get task info for filename
            $task = \App\Modules\Projects\Models\EnquiryTask::with('enquiry')->findOrFail($taskId);
            $enquiryNumber = $task->enquiry->enquiry_number ?? 'TASK' . $taskId;
            $filename = "Materials_Template_{$enquiryNumber}_" . now()->format('Ymd') . ".xlsx";
            
            return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
            
        } catch (\Exception $e) {
            \Log::error('Failed to generate materials template', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Failed to generate template',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Upload and validate Excel template
     *
     * @OA\Post(
     *     path="/api/projects/tasks/{taskId}/materials/template/upload",
     *     tags={"Materials"},
     *     summary="Upload Excel template for validation",
     *     description="Uploads and validates an Excel file, returns preview data with errors/warnings",
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
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                     format="binary",
     *                     description="Excel file to upload"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="File validated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="preview", type="object"),
     *             @OA\Property(property="stats", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
             *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function uploadTemplate(Request $request, int $taskId): JsonResponse
    {
        $this->authorizeMaterialsMutation($taskId);

        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:5120', // 5MB max
        ]);
        
        try {
            // Import and parse the Excel file
            $import = new \App\Modules\Projects\Imports\MaterialsTemplateImport($taskId);
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('file'));
            
            // Get preview data
            $previewData = $import->getPreviewData();
            
            // Check if there are blocking errors
            $hasBlockingErrors = count($previewData['errors']) > 0;
            
            return response()->json([
                'success' => !$hasBlockingErrors,
                'preview' => $previewData,
                'stats' => $previewData['stats'],
                'message' => $hasBlockingErrors 
                    ? 'File has errors that must be fixed before importing' 
                    : 'File validated successfully',
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to process materials template upload', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process file: ' . ($e->getMessage() ?: 'Unknown error'),
            ], 422);
        }
    }

    /**
     * Check if materials approval is gated by design requirements
     */
    private function checkDesignApprovalGate(int $taskId): array
    {
        try {
            $currentTask = \App\Modules\Projects\Models\EnquiryTask::find($taskId);
            if (!$currentTask) {
                return ['is_gated' => false, 'message' => 'Task not found'];
            }

            // Find design task for the same enquiry
            $designTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $currentTask->project_enquiry_id)
                ->where('type', 'design')
                ->first();

            // If no design task exists, there is no gate
            if (!$designTask) {
                return ['is_gated' => false, 'message' => 'No design task required for this project preset.'];
            }

            // Check for approved assets in the design task
            $hasApprovedAssets = \App\Models\DesignAsset::where('enquiry_task_id', $designTask->id)
                ->where('status', 'approved')
                ->exists();

            if (!$hasApprovedAssets) {
                return [
                    'is_gated' => true,
                    'message' => 'Materials approval is locked until the Design Task has approved assets. Please ensure at least one design file is approved by the client or manager.',
                    'design_task_id' => $designTask->id
                ];
            }

            return [
                'is_gated' => false,
                'message' => 'Design requirements met.',
                'design_task_id' => $designTask->id
            ];
        } catch (\Exception $e) {
            \Log::error('Design gate check failed', ['error' => $e->getMessage()]);
            return ['is_gated' => false, 'message' => 'Gate check errored. Contact admin.'];
        }
    }

    /**
     * Reading project tasks is intentionally transparent, but changing the
     * bill of materials is not. Frontend `readonly` flags are presentation,
     * never an authorization boundary.
     */
    private function authorizeMaterialsMutation(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);
        abort_unless($task->type === 'materials', 422, 'This action is only valid for a materials task.');
        abort_unless(auth()->user() && $task->isUserAuthorized(auth()->user()), 403, 'You can only change a materials task in your assigned work pool.');

        return $task;
    }
}
