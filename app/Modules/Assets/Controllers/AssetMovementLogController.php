<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetHireRequest;
use App\Modules\Assets\Services\AssetHireRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetMovementLogController extends Controller
{
    public function __construct(protected AssetHireRequestService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $fullVisibility = $user->hasRole(['Super Admin', 'Admin', 'HR']) || $user->is_dept_lead;

        $query = AssetHireRequest::with([
            'asset.assetCategory', 'forUser', 'requestedBy', 'approvedBy', 'rejectedBy', 'returnedBy',
        ]);

        if (!$fullVisibility) {
            $query->where(function ($q) use ($user) {
                $q->where('requested_by', $user->id)
                    ->orWhere('for_user_id', $user->id)
                    ->orWhere('approved_by', $user->id)
                    ->orWhere('rejected_by', $user->id)
                    ->orWhere('returned_by', $user->id);
            });
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->asset_id);
        }

        $requests = $query->get();
        $events = collect();

        foreach ($requests as $req) {
            // Skip if asset was deleted
            if (!$req->asset) continue;

            $assetSummary = [
                'id' => $req->asset->id,
                'asset_code' => $req->asset->asset_code,
                'name' => $req->asset->name,
                'image_url' => $req->asset->image_url,
            ];

            $context = null;
            try {
                if ($req->project_id) {
                    $context = $req->project?->enquiry?->title ?? $req->project?->enquiry?->job_number;
                }
            } catch (\Exception $e) {
                $context = null;
            }

            if (in_array($req->status, [AssetHireRequest::STATUS_APPROVED, AssetHireRequest::STATUS_RETURNED])) {
                $events->push(['kind' => 'out', 'event' => 'dispatched', 'status' => 'approved', 'hire_request_id' => $req->id, 'request_type' => $req->request_type, 'asset' => $assetSummary, 'person' => $req->forUser?->name, 'context' => $context, 'at' => $req->approved_at]);
            } elseif ($req->status === AssetHireRequest::STATUS_PENDING) {
                $events->push(['kind' => 'out', 'event' => 'requested', 'status' => 'pending', 'hire_request_id' => $req->id, 'request_type' => $req->request_type, 'asset' => $assetSummary, 'person' => $req->forUser?->name, 'context' => $context, 'at' => $req->created_at]);
            } elseif ($req->status === AssetHireRequest::STATUS_REJECTED) {
                $events->push(['kind' => 'out', 'event' => 'rejected', 'status' => 'rejected', 'hire_request_id' => $req->id, 'request_type' => $req->request_type, 'asset' => $assetSummary, 'person' => $req->forUser?->name, 'context' => $context, 'at' => $req->rejected_at]);
            }

            if ($req->status === AssetHireRequest::STATUS_RETURNED) {
                $events->push(['kind' => 'in', 'event' => 'returned', 'status' => 'returned', 'hire_request_id' => $req->id, 'request_type' => $req->request_type, 'asset' => $assetSummary, 'person' => $req->forUser?->name, 'context' => $req->return_condition ? "condition {$req->return_condition}" : $context, 'at' => $req->actual_return_date]);
            }
        }

        $sorted = $events->filter(fn ($e) => $e['at'] !== null)->sortByDesc('at')->values();
        $perPage = (int) $request->get('per_page', 50);
        $page = (int) $request->get('page', 1);
        $paged = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json(['data' => $paged, 'current_page' => $page, 'last_page' => (int) ceil($sorted->count() / $perPage) ?: 1, 'total' => $sorted->count()]);
    }
}
