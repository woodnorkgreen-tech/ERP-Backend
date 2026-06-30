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

    /**
     * A unified, chronological feed of everything that's happened to assets:
     * requested, approved (handed out), rejected, returned. Built from the
     * hire request lifecycle rather than a separate log table — every status
     * change already has a timestamp and an actor, so this just reshapes
     * that into "movement events" rather than duplicating the data.
     *
     * Visibility: Admin/HR/Super Admin and department leads see everything.
     * Everyone else only sees movements for assets they've personally been
     * involved with (requested, held, approved, rejected, or returned) —
     * same rule as an individual asset's history panel, just applied
     * across all assets at once.
     */
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

        // One row per leg of the journey: OUT when it leaves (whether still
        // pending approval or already dispatched), IN when it comes back.
        // A returned item shows two rows — its OUT leg and its IN leg —
        // not three; the "requested" and "approved" legs collapse into a
        // single OUT row once approved, instead of listing both.
        $events = collect();

        foreach ($requests as $req) {
            $assetSummary = [
                'id' => $req->asset->id,
                'asset_code' => $req->asset->asset_code,
                'name' => $req->asset->name,
                'image_url' => $req->asset->image_url,
            ];
            $context = $req->project?->enquiry?->title ?? $req->project?->enquiry?->job_number;

            if (in_array($req->status, [AssetHireRequest::STATUS_APPROVED, AssetHireRequest::STATUS_RETURNED])) {
                $events->push([
                    'kind' => 'out',
                    'event' => 'dispatched',
                    'status' => 'approved',
                    'hire_request_id' => $req->id,
                    'request_type' => $req->request_type,
                    'asset' => $assetSummary,
                    'person' => $req->forUser?->name,
                    'context' => $context,
                    'at' => $req->approved_at,
                ]);
            } elseif ($req->status === AssetHireRequest::STATUS_PENDING) {
                $events->push([
                    'kind' => 'out',
                    'event' => 'requested',
                    'status' => 'pending',
                    'hire_request_id' => $req->id,
                    'request_type' => $req->request_type,
                    'asset' => $assetSummary,
                    'person' => $req->forUser?->name,
                    'context' => $context,
                    'at' => $req->created_at,
                ]);
            } elseif ($req->status === AssetHireRequest::STATUS_REJECTED) {
                $events->push([
                    'kind' => 'out',
                    'event' => 'rejected',
                    'status' => 'rejected',
                    'hire_request_id' => $req->id,
                    'request_type' => $req->request_type,
                    'asset' => $assetSummary,
                    'person' => $req->forUser?->name,
                    'context' => $context,
                    'at' => $req->rejected_at,
                ]);
            }

            if ($req->status === AssetHireRequest::STATUS_RETURNED) {
                $events->push([
                    'kind' => 'in',
                    'event' => 'returned',
                    'status' => 'returned',
                    'hire_request_id' => $req->id,
                    'request_type' => $req->request_type,
                    'asset' => $assetSummary,
                    'person' => $req->forUser?->name,
                    'context' => $req->return_condition ? "condition {$req->return_condition}" : $context,
                    'at' => $req->actual_return_date,
                ]);
            }
        }

        $sorted = $events->filter(fn ($e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->values();

        // Lightweight manual pagination over the in-memory event list.
        $perPage = (int) $request->get('per_page', 50);
        $page = (int) $request->get('page', 1);
        $paged = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $paged,
            'current_page' => $page,
            'last_page' => (int) ceil($sorted->count() / $perPage) ?: 1,
            'total' => $sorted->count(),
        ]);
    }
}
