<?php

namespace App\Http\Controllers;

use App\Models\TaskBudgetData;
use App\Services\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Budget Controller
 * Handles budget data management for tasks
 */
class BudgetController extends Controller
{
    private BudgetService $budgetService;

    public function __construct(BudgetService $budgetService)
    {
        $this->budgetService = $budgetService;
    }

    /**
     * Get budget data for a task
     */
    public function getBudgetData(int $taskId): JsonResponse
    {
        try {
            $budgetData = $this->budgetService->getBudgetData($taskId);

            if (!$budgetData) {
                return response()->json([
                    'message' => 'Budget data not found'
                ], 404);
            }

            // Get materials task approval status
            $materialsTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $budgetData->task->project_enquiry_id ?? 0)
                ->where('type', 'materials')
                ->first();
            
            $materialsApprovalStatus = null;
            if ($materialsTask) {
                $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
                if ($materialsData) {
                    $materialsApprovalStatus = $materialsData->project_info['approval_status'] ?? null;
                }
            }

            // Transform the response to match frontend expectations
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data ?? [],
                'labour' => $budgetData->labour_data ?? [],
                'expenses' => $budgetData->expenses_data ?? [],
                'logistics' => $budgetData->logistics_data ?? [],
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status ?? 'draft',
                'materialsImportInfo' => [
                    'importedAt' => $budgetData->materials_imported_at,
                    'importedFromTask' => $budgetData->materials_imported_from_task,
                    'manuallyModified' => $budgetData->materials_manually_modified ?? false,
                    'importMetadata' => $budgetData->materials_import_metadata
                ],
                'materialsApprovalStatus' => $materialsApprovalStatus
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Budget data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save budget data for a task
     */
    public function saveBudgetData(Request $request, int $taskId): JsonResponse
    {
        try {
            $data = $request->validate([
                'projectInfo' => 'sometimes|array',
                'materials' => 'sometimes|array',
                'labour' => 'sometimes|array',
                'expenses' => 'sometimes|array',
                'logistics' => 'sometimes|array',
                'budgetSummary' => 'sometimes|array',
                'lastImportDate' => 'sometimes|date'
            ]);

            $budgetData = $this->budgetService->saveBudgetData($taskId, $data);

            return response()->json([
                'data' => $budgetData,
                'message' => 'Budget data saved successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit budget for approval
     */
    public function submitForApproval(int $taskId): JsonResponse
    {
        try {
            $result = $this->budgetService->submitForApproval($taskId);

            return response()->json([
                'data' => $result,
                'message' => 'Budget submitted for approval successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to submit budget for approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import materials into budget
     */
    public function importMaterials(int $taskId): JsonResponse
    {
        try {
            $budgetData = $this->budgetService->importMaterials($taskId);

            // Transform the response to match frontend expectations
            $response = [
                'projectInfo' => $budgetData->project_info,
                'materials' => $budgetData->materials_data ?? [],
                'labour' => $budgetData->labour_data ?? [],
                'expenses' => $budgetData->expenses_data ?? [],
                'logistics' => $budgetData->logistics_data ?? [],
                'budgetSummary' => $budgetData->budget_summary,
                'status' => $budgetData->status ?? 'draft',
                'materialsImportInfo' => [
                    'importedAt' => $budgetData->materials_imported_at,
                    'importedFromTask' => $budgetData->materials_imported_from_task,
                    'manuallyModified' => $budgetData->materials_manually_modified ?? false,
                    'importMetadata' => $budgetData->materials_import_metadata
                ]
            ];

            return response()->json([
                'data' => $response,
                'message' => 'Materials imported successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import materials',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkMaterialsUpdate(int $taskId): JsonResponse
    {
        try {
            $result = $this->budgetService->checkMaterialsUpdate($taskId);

            return response()->json([
                'data' => $result,
                'message' => 'Materials update check completed'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to check materials update',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new budget version
     * Captures a complete snapshot of the budget including reference to current material version
     */
    public function createBudgetVersion(Request $request, int $taskId): JsonResponse
    {
        \Log::info("createBudgetVersion called for task ID: {$taskId}");
        
        try {
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)
                ->with('budgetAdditions')
                ->first();

            if (!$budgetData) {
                return response()->json(['message' => 'Budget data not found'], 404);
            }

            // Find latest material version for this enquiry (if exists)
            $materialsVersionId = null;
            $budgetTask = \App\Modules\Projects\Models\EnquiryTask::find($taskId);
            if ($budgetTask) {
                $materialsTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $budgetTask->project_enquiry_id)
                    ->where('type', 'materials')
                    ->first();
                
                if ($materialsTask) {
                    $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
                    if ($materialsData) {
                        $latestMaterialVersion = $materialsData->versions()->orderBy('version_number', 'desc')->first();
                        $materialsVersionId = $latestMaterialVersion->id ?? null;
                    }
                }
            }

            // Calculate next version number
            $latestVersion = $budgetData->versions()->max('version_number') ?? 0;
            $newVersionNumber = $latestVersion + 1;

            // Build complete snapshot
            $snapshot = [
                'project_info' => $budgetData->project_info,
                'materials_data' => $budgetData->materials_data,
                'labour_data' => $budgetData->labour_data,
                'expenses_data' => $budgetData->expenses_data,
                'logistics_data' => $budgetData->logistics_data,
                'budget_summary' => $budgetData->budget_summary,
                'status' => $budgetData->status,
                'materials_import_info' => [
                    'importedAt' => $budgetData->materials_imported_at,
                    'importedFromTask' => $budgetData->materials_imported_from_task,
                    'manuallyModified' => $budgetData->materials_manually_modified ?? false,
                    'importMetadata' => $budgetData->materials_import_metadata
                ],
                'budget_additions' => $budgetData->budgetAdditions->map(function ($addition) {
                    return [
                        'id' => $addition->id,
                        'title' => $addition->title,
                        'description' => $addition->description,
                        'materials' => $addition->materials,
                        'labour' => $addition->labour,
                        'expenses' => $addition->expenses,
                        'logistics' => $addition->logistics,
                        'status' => $addition->status,
                    ];
                })->toArray()
            ];

            // Create version
            $version = $budgetData->versions()->create([
                'version_number' => $newVersionNumber,
                'label' => $request->input('label', 'Version ' . $newVersionNumber . ' - ' . now()->format('M d, Y h:i A')),
                'data' => $snapshot,
                'created_by' => auth()->id() ?? 1,
                'materials_version_id' => $materialsVersionId, // Link to material version
                'source_updated_at' => $budgetData->updated_at,
            ]);

            \Log::info('Budget version created successfully', [
                'version_id' => $version->id,
                'version_number' => $newVersionNumber,
                'task_id' => $taskId,
                'materials_version_id' => $materialsVersionId
            ]);

            return response()->json([
                'message' => 'Version created successfully',
                'data' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'label' => $version->label,
                    'created_at' => $version->created_at,
                    'created_by_name' => $version->creator->name ?? 'Unknown',
                    'materials_version_id' => $materialsVersionId
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to create budget version', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create version',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get all versions for a budget
     */
    public function getBudgetVersions(int $taskId): JsonResponse
    {
        try {
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

            if (!$budgetData) {
                return response()->json(['data' => []]);
            }

            $versions = $budgetData->versions()
                ->with(['creator', 'materialVersion'])
                ->orderBy('version_number', 'desc')
                ->get()
                ->map(function ($version) {
                    return [
                        'id' => $version->id,
                        'version_number' => $version->version_number,
                        'label' => $version->label,
                        'created_at' => $version->created_at,
                        'created_by_name' => $version->creator->name ?? 'Unknown',
                        'materials_version_id' => $version->materials_version_id,
                        'materials_version_number' => $version->materialVersion->version_number ?? null,
                        'source_updated_at' => $version->source_updated_at,
                    ];
                });

            return response()->json(['data' => $versions]);

        } catch (\Exception $e) {
            \Log::error('Failed to get budget versions', [
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
     * Restore a budget to a specific version
     * Implements conflict detection and material version mismatch warnings
     */
    public function restoreBudgetVersion(int $taskId, int $versionId): JsonResponse
    {
        try {
            $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            
            if (!$budgetData) {
                return response()->json(['message' => 'Budget data not found'], 404);
            }

            $version = \App\Models\BudgetVersion::with('materialVersion')->find($versionId);
            
            if (!$version) {
                return response()->json(['message' => 'Version not found'], 404);
            }

            // Validate version belongs to this budget
            if ($version->task_budget_data_id !== $budgetData->id) {
                return response()->json(['message' => 'Invalid version for this budget'], 400);
            }

            // CONFLICT DETECTION: Check if source data changed
            $hasChanged = false;
            $changeWarning = null;
            if ($version->source_updated_at && $budgetData->updated_at) {
                if ($budgetData->updated_at->gt($version->source_updated_at)) {
                    $hasChanged = true;
                    $changeWarning = 'Warning: Budget has been modified since this version was created. Restoring will overwrite current changes.';
                }
            }

            // MATERIAL VERSION MISMATCH DETECTION
            $materialWarning = null;
            if ($version->materials_version_id) {
                // Get current material version
                $budgetTask = \App\Modules\Projects\Models\EnquiryTask::find($taskId);
                $materialsTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $budgetTask->project_enquiry_id)
                    ->where('type', 'materials')
                    ->first();
                
                if ($materialsTask) {
                    $materialsData = \App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
                    if ($materialsData) {
                        $currentMaterialVersion = $materialsData->versions()->orderBy('version_number', 'desc')->first();
                        if ($currentMaterialVersion && $currentMaterialVersion->id !== $version->materials_version_id) {
                            $materialWarning = sprintf(
                                'Warning: This budget references Material Version %s, but current materials are on Version %s.',
                                $version->materialVersion->version_number ?? 'Unknown',
                                $currentMaterialVersion->version_number
                            );
                        }
                    }
                }
            }

            $restoredData = $version->data;

            // Delete existing budget additions and restore from snapshot
            \DB::transaction(function () use ($budgetData, $restoredData) {
                // Delete existing budget additions
                $budgetData->budgetAdditions()->delete();

                // Recreate budget additions from snapshot
                foreach ($restoredData['budget_additions'] ?? [] as $additionData) {
                    $budgetData->budgetAdditions()->create([
                        'title' => $additionData['title'],
                        'description' => $additionData['description'],
                        'materials' => $additionData['materials'] ?? [],
                        'labour' => $additionData['labour'] ?? [],
                        'expenses' => $additionData['expenses'] ?? [],
                        'logistics' => $additionData['logistics'] ?? [],
                        'status' => 'pending_approval', // Reset to pending
                        'created_by' => auth()->id() ?? 1,
                    ]);
                }

                // Update budget data, reset status to draft
                $budgetData->update([
                    'project_info' => $restoredData['project_info'],
                    'materials_data' => $restoredData['materials_data'],
                    'labour_data' => $restoredData['labour_data'],
                    'expenses_data' => $restoredData['expenses_data'],
                    'logistics_data' => $restoredData['logistics_data'],
                    'budget_summary' => $restoredData['budget_summary'],
                    'status' => 'draft', // RESET STATUS (Success Criteria #6)
                    'materials_imported_at' => $restoredData['materials_import_info']['importedAt'] ?? null,
                    'materials_imported_from_task' => $restoredData['materials_import_info']['importedFromTask'] ?? null,
                    'materials_manually_modified' => $restoredData['materials_import_info']['manuallyModified'] ?? false,
                    'materials_import_metadata' => $restoredData['materials_import_info']['importMetadata'] ?? null,
                ]);
            });

            \Log::info('Budget version restored successfully', [
                'task_id' => $taskId,
                'version_id' => $versionId,
                'version_number' => $version->version_number,
                'had_conflicts' => $hasChanged,
                'material_version_mismatch' => !empty($materialWarning)
            ]);

            $warnings = array_filter([$changeWarning, $materialWarning]);

            return response()->json([
                'message' => 'Budget restored to version ' . $version->version_number,
                'warnings' => $warnings,
                'data' => $budgetData,
                'status_reset_to_draft' => true
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to restore budget version', [
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
}
