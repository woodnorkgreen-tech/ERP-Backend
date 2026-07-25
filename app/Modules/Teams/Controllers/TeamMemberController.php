<?php

namespace App\Modules\Teams\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Models\TeamsTask;
use App\Modules\Teams\Requests\StoreTeamMemberRequest;
use App\Modules\Teams\Requests\UpdateTeamMemberRequest;
use App\Modules\Teams\Services\TeamMemberService;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;

class TeamMemberController extends Controller
{
    public function __construct(
        private TeamMemberService $memberService
    ) {}

    public function index(int $taskId, int $teamTaskId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);
        $members = $this->memberService->getTeamMembers($teamTaskId);

        return response()->json([
            'message' => 'Team members retrieved successfully',
            'data' => $members
        ]);
    }

    public function store(StoreTeamMemberRequest $request, int $taskId, int $teamTaskId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);
        $member = $this->memberService->addMember($teamTaskId, $request->validated());

        return response()->json([
            'message' => 'Team member added successfully',
            'data' => $member
        ], 201);
    }

    public function update(UpdateTeamMemberRequest $request, int $taskId, int $teamTaskId, int $memberId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);
        TeamsMember::where('teams_task_id', $teamTaskId)->findOrFail($memberId);
        $member = $this->memberService->updateMember($memberId, $request->validated());

        return response()->json([
            'message' => 'Team member updated successfully',
            'data' => $member
        ]);
    }

    public function destroy(int $taskId, int $teamTaskId, int $memberId): JsonResponse
    {
        $this->authorizeTask($taskId);
        TeamsTask::where('task_id', $taskId)->findOrFail($teamTaskId);
        TeamsMember::where('teams_task_id', $teamTaskId)->findOrFail($memberId);
        $this->memberService->removeMember($memberId);

        return response()->json([
            'message' => 'Team member removed successfully'
        ]);
    }

    private function authorizeTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::where('type', 'teams')->findOrFail($taskId);
        abort_unless(auth()->check() && $task->isUserAuthorized(auth()->user()), 403);

        return $task;
    }
}
