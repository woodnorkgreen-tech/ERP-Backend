<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetHireRequest;
use App\Modules\Assets\Requests\StoreAssetHireRequestRequest;
use App\Modules\Assets\Resources\AssetHireRequestResource;
use App\Modules\Assets\Services\AssetHireRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetHireRequestController extends Controller
{
    public function __construct(protected AssetHireRequestService $service)
    {
    }

    private function relations(): array
    {
        return ['asset.assetCategory', 'project.enquiry', 'requestedBy', 'forUser', 'approvedBy', 'rejectedBy', 'returnedBy'];
    }

    /**
     * List requests, scoped to what this user is allowed to see:
     * Super Admin / HR see everything; everyone else sees requests they
     * made, requests for them, requests they've acted on, and pending
     * requests waiting on their approval.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AssetHireRequest::with($this->relations());

        if (!$user->hasRole(['Super Admin', 'HR'])) {
            $managedDeptIds = $this->service->managedDepartmentIds($user);

            $query->where(function ($q) use ($user, $managedDeptIds) {
                $q->where('requested_by', $user->id)
                    ->orWhere('for_user_id', $user->id)
                    ->orWhere('approved_by', $user->id)
                    ->orWhere('rejected_by', $user->id);

                if (!empty($managedDeptIds)) {
                    $q->orWhereHas('forUser', function ($uq) use ($managedDeptIds) {
                        $uq->whereIn('department_id', $managedDeptIds);
                    });
                }
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('request_type')) {
            $query->where('request_type', $request->request_type);
        }
        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->asset_id);
        }

        $requests = $query->latest()->paginate($request->get('per_page', 50));

        // Flag which ones THIS user can act on, for the list's approve/reject buttons.
        $requests->getCollection()->transform(function ($req) use ($user) {
            $req->can_approve = $req->status === AssetHireRequest::STATUS_PENDING && $this->service->canApprove($user, $req);
            return $req;
        });

        return response()->json([
            'data' => AssetHireRequestResource::collection($requests),
            'current_page' => $requests->currentPage(),
            'last_page' => $requests->lastPage(),
            'total' => $requests->total(),
            'stats' => [
                'pending_count' => AssetHireRequest::pending()->count(),
                'awaiting_return_count' => AssetHireRequest::awaitingReturn()->count(),
            ],
        ]);
    }

    /**
     * Every request ever made for one specific asset — pending, approved,
     * rejected, returned, all of it, oldest action first to newest. This is
     * what an asset's own "Hire History" panel calls; nothing is ever
     * deleted on return, it just changes status, so this always shows
     * the complete story for that asset, one row per request (never repeats
     * the asset itself — it's the same asset throughout, just whose hands
     * it passed through and when).
     */
    public function history(Request $request, $assetId): JsonResponse
    {
        $user = $request->user();

        if (!$this->service->canViewAssetHistory($user, (int) $assetId)) {
            return response()->json(['message' => 'You are not authorised to view this asset\'s history.'], 403);
        }

        $requests = AssetHireRequest::with($this->relations())
            ->where('asset_id', $assetId)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => AssetHireRequestResource::collection($requests),
        ]);
    }

    public function store(StoreAssetHireRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($data['request_type'] === AssetHireRequest::TYPE_ASSIGN && !$this->service->canCreateAssignType($user)) {
            return response()->json([
                'message' => 'Only a department lead, Admin, HR, or Super Admin can assign a company asset directly.',
            ], 403);
        }

        try {
            $hireRequest = $this->service->create($data, $user);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hireRequest->load($this->relations());

        return response()->json([
            'message' => 'Request submitted — routed for approval.',
            'data' => new AssetHireRequestResource($hireRequest),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $hireRequest = AssetHireRequest::with($this->relations())->findOrFail($id);
        $hireRequest->can_approve = $hireRequest->status === AssetHireRequest::STATUS_PENDING
            && $this->service->canApprove($request->user(), $hireRequest);

        return response()->json(['data' => new AssetHireRequestResource($hireRequest)]);
    }

    public function approve(Request $request, $id): JsonResponse
    {
        $hireRequest = AssetHireRequest::with('forUser')->findOrFail($id);
        $user = $request->user();

        if (!$this->service->canApprove($user, $hireRequest)) {
            return response()->json(['message' => 'You are not authorised to approve this request.'], 403);
        }

        try {
            $hireRequest = $this->service->approve($hireRequest, $user);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hireRequest->load($this->relations());

        return response()->json([
            'message' => 'Approved — asset handed out.',
            'data' => new AssetHireRequestResource($hireRequest),
        ]);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $hireRequest = AssetHireRequest::with('forUser')->findOrFail($id);
        $user = $request->user();

        if (!$this->service->canApprove($user, $hireRequest)) {
            return response()->json(['message' => 'You are not authorised to act on this request.'], 403);
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $hireRequest = $this->service->reject($hireRequest, $user, $validated['reason'] ?? null);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hireRequest->load($this->relations());

        return response()->json([
            'message' => 'Request rejected.',
            'data' => new AssetHireRequestResource($hireRequest),
        ]);
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $hireRequest = AssetHireRequest::findOrFail($id);

        try {
            $hireRequest = $this->service->cancel($hireRequest, $request->user());
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hireRequest->load($this->relations());

        return response()->json([
            'message' => 'Request cancelled.',
            'data' => new AssetHireRequestResource($hireRequest),
        ]);
    }

    public function markReturned(Request $request, $id): JsonResponse
    {
        $hireRequest = AssetHireRequest::with('forUser')->findOrFail($id);
        $user = $request->user();

        // The approver is the one expected to close the loop, but Admin/Super Admin/HR can too.
        $isOriginalApprover = $hireRequest->approved_by === $user->id;
        if (!$isOriginalApprover && !$user->hasRole(['Super Admin', 'Admin', 'HR'])) {
            return response()->json(['message' => 'Only the approver (or Admin/HR) can record this return.'], 403);
        }

        $validated = $request->validate([
            'actual_return_date' => ['nullable', 'date'],
            'return_condition' => ['nullable', 'in:New,Good,Fair,Poor'],
        ]);

        try {
            $hireRequest = $this->service->markReturned($hireRequest, $user, $validated);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hireRequest->load($this->relations());

        return response()->json([
            'message' => 'Marked as returned — asset is available again.',
            'data' => new AssetHireRequestResource($hireRequest),
        ]);
    }
}
