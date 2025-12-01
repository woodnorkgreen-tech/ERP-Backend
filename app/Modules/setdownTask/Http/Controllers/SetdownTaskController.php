<?php

namespace App\Modules\setdownTask\Http\Controllers;

use App\Modules\setdownTask\Services\SetdownTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SetdownTaskController extends Controller
{
    protected SetdownTaskService $setdownService;

    public function __construct(SetdownTaskService $setdownService)
    {
        $this->setdownService = $setdownService;
    }

    /**
     * Get setdown data for a task
     */
    public function show(int $taskId): JsonResponse
    {
        try {
            $setdownData = $this->setdownService->getSetdownForTask($taskId);

            if (!$setdownData) {
                return response()->json([
                    'message' => 'No setdown data found',
                    'data' => null
                ]);
            }

            return response()->json([
                'message' => 'Setdown data retrieved successfully',
                'data' => $setdownData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve setdown data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save documentation notes
     */
    public function saveDocumentation(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'setdown_notes' => 'nullable|string',
                'completion_notes' => 'nullable|string',
            ]);

            $setdownTask = $this->setdownService->saveDocumentation($taskId, $validated);

            return response()->json([
                'message' => 'Documentation saved successfully',
                'data' => $setdownTask
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save documentation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a photo
     */
    public function uploadPhoto(Request $request, int $taskId): JsonResponse
    {
        try {
            \Log::info('SetdownTaskController::uploadPhoto called', [
                'taskId' => $taskId,
                'hasFile' => $request->hasFile('photo'),
                'fileCount' => $request->allFiles(),
            ]);

            $validated = $request->validate([
                'photo' => 'required|image|max:10240', // 10MB max
                'description' => 'nullable|string',
            ]);

            \Log::info('SetdownTaskController::uploadPhoto validated', ['validated' => $validated]);

            $photo = $this->setdownService->uploadPhoto(
                $taskId,
                $request->file('photo'),
                $validated['description'] ?? null
            );

            \Log::info('SetdownTaskController::uploadPhoto success', ['photo' => $photo]);

            return response()->json([
                'message' => 'Photo uploaded successfully',
                'data' => $photo
            ]);
        } catch (\Exception $e) {
            \Log::error('SetdownTaskController::uploadPhoto failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to upload photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a photo
     */
    public function deletePhoto(int $taskId, int $photoId): JsonResponse
    {
        try {
            $this->setdownService->deletePhoto($taskId, $photoId);

            return response()->json([
                'message' => 'Photo deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add an issue
     */
    public function addIssue(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'category' => 'required|in:equipment,venue,team,safety,other',
                'priority' => 'required|in:low,medium,high,critical',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $issue = $this->setdownService->addIssue($taskId, $validated);

            return response()->json([
                'message' => 'Issue added successfully',
                'data' => $issue
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add issue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an issue
     */
    public function updateIssue(Request $request, int $taskId, int $issueId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'nullable|in:open,in_progress,resolved',
                'resolution' => 'nullable|string',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            $issue = $this->setdownService->updateIssue($taskId, $issueId, $validated);

            return response()->json([
                'message' => 'Issue updated successfully',
                'data' => $issue
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update issue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an issue
     */
    public function deleteIssue(int $taskId, int $issueId): JsonResponse
    {
        try {
            $this->setdownService->deleteIssue($taskId, $issueId);

            return response()->json([
                'message' => 'Issue deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete issue',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
