<?php

namespace App\Modules\Teams\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Models\TeamsMember;
use App\Modules\Teams\Requests\StoreTeamMemberRequest;
use App\Modules\Teams\Requests\UpdateTeamMemberRequest;
use App\Modules\Teams\Services\TeamMemberService;
use Illuminate\Http\JsonResponse;

class TeamMemberController extends Controller
{
    public function __construct(
        private TeamMemberService $memberService
    ) {}

    public function index(int $teamTaskId): JsonResponse
    {
        $members = $this->memberService->getTeamMembers($teamTaskId);

        return response()->json([
            'message' => 'Team members retrieved successfully',
            'data' => $members
        ]);
    }

    public function store(StoreTeamMemberRequest $request, int $teamTaskId): JsonResponse
    {
        $member = $this->memberService->addMember($teamTaskId, $request->validated());

        return response()->json([
            'message' => 'Team member added successfully',
            'data' => $member
        ], 201);
    }

    public function update(UpdateTeamMemberRequest $request, int $teamTaskId, int $memberId): JsonResponse
    {
        $member = $this->memberService->updateMember($memberId, $request->validated());

        return response()->json([
            'message' => 'Team member updated successfully',
            'data' => $member
        ]);
    }

    public function destroy(int $teamTaskId, int $memberId): JsonResponse
    {
        $this->memberService->removeMember($memberId);

        return response()->json([
            'message' => 'Team member removed successfully'
        ]);
    }
}