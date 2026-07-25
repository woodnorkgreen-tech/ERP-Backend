<?php

namespace App\Modules\Teams\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Models\TeamsTask;
use App\Modules\Teams\Models\TeamCategory;
use App\Modules\Teams\Models\TeamCategoryType;
use App\Modules\Teams\Models\TeamType;
use App\Modules\Teams\Models\TeamMember;
use App\Modules\Teams\Services\TeamsTaskService;
use App\Modules\Teams\Requests\StoreTeamsTaskRequest;
use App\Modules\Teams\Requests\UpdateTeamsTaskRequest;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamsTaskController extends Controller
{
    public function __construct(
        private TeamsTaskService $teamsService
    ) {}

    public function index(int $taskId): JsonResponse
    {
        $this->authorizeTask($taskId);

        try {
            $teams = $this->teamsService->getTeamsForTask($taskId);

            return response()->json([
                'message' => 'Teams retrieved successfully',
                'data' => $teams
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching teams for task: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve teams',
                'data' => []
            ], 500);
        }
    }

    public function store(StoreTeamsTaskRequest $request, int $taskId): JsonResponse
    {
        $this->authorizeTask($taskId);

        try {
            $team = $this->teamsService->createTeamTask($taskId, $request->validated());

            return response()->json([
                'message' => 'Team task created successfully',
                'data' => $team
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating team task: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create team task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateTeamsTaskRequest $request, int $taskId, int $teamTaskId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);

        try {
            $team = $this->teamsService->updateTeamTask($teamTaskId, $request->validated());

            return response()->json([
                'message' => 'Team task updated successfully',
                'data' => $team
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating team task: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update team task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(int $taskId, int $teamTaskId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);

        try {
            $this->teamsService->deleteTeamTask($teamTaskId);

            return response()->json([
                'message' => 'Team task deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting team task: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete team task',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkAssign(Request $request, int $taskId): JsonResponse
    {
        $this->authorizeTask($taskId);

        try {
            $assignments = $request->input('assignments', []);
            $teams = $this->teamsService->bulkAssignTeams($taskId, $assignments);

            return response()->json([
                'message' => 'Teams assigned successfully',
                'data' => $teams
            ]);
        } catch (\Exception $e) {
            \Log::error('Error bulk assigning teams: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to assign teams',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTeamTypes(): JsonResponse
    {
        try {
            $teamTypes = TeamType::active()->orderBy('sort_order')->get();

            return response()->json([
                'message' => 'Team types retrieved successfully',
                'data' => $teamTypes
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching team types: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve team types',
                'data' => []
            ], 500);
        }
    }

    public function getTeamCategories(): JsonResponse
    {
        try {
            $categories = TeamCategory::active()
                ->with('availableTypes.teamType')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'message' => 'Team categories retrieved successfully',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching team categories: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve team categories',
                'data' => []
            ], 500);
        }
    }

    private function authorizeTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::where('type', 'teams')->findOrFail($taskId);
        abort_unless(auth()->check() && $task->isUserAuthorized(auth()->user()), 403);

        return $task;
    }
}
