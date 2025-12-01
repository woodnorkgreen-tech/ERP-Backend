<?php

namespace App\Modules\setupTask\Http\Controllers;

use App\Modules\setupTask\Services\SetupTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SetupTaskController extends Controller
{
    protected SetupTaskService $setupService;

    public function __construct(SetupTaskService $setupService)
    {
        $this->setupService = $setupService;
    }

    /**
     * Get setup data for a task
     */
    public function show(int $taskId): JsonResponse
    {
        try {
            $setupData = $this->setupService->getSetupForTask($taskId);

            if (!$setupData) {
                return response()->json([
                    'message' => 'No setup data found',
                    'data' => null
                ]);
            }

            return response()->json([
                'message' => 'Setup data retrieved successfully',
                'data' => $setupData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve setup data',
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
                'setup_notes' => 'nullable|string',
                'completion_notes' => 'nullable|string',
            ]);

            $setupTask = $this->setupService->saveDocumentation($taskId, $validated);

            return response()->json([
                'message' => 'Documentation saved successfully',
                'data' => $setupTask
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
            $validated = $request->validate([
                'photo' => 'required|image|max:10240', // 10MB max
                'description' => 'nullable|string',
            ]);

            $photo = $this->setupService->uploadPhoto(
                $taskId,
                $request->file('photo'),
                $validated['description'] ?? null
            );

            return response()->json([
                'message' => 'Photo uploaded successfully',
                'data' => $photo
            ]);
        } catch (\Exception $e) {
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
            $this->setupService->deletePhoto($taskId, $photoId);

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

            $issue = $this->setupService->addIssue($taskId, $validated);

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

            $issue = $this->setupService->updateIssue($taskId, $issueId, $validated);

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
            $this->setupService->deleteIssue($taskId, $issueId);

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
