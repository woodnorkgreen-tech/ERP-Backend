<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetHireRequest;
use App\Modules\Assets\Resources\AssetHireRequestResource;
use App\Modules\ClientService\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetClientPortalController extends Controller
{
    /**
     * Who can see all clients:
     * Super Admin, Admin, HR, and department leads get the full list.
     * Everyone else only sees assets where they are the assigned custodian.
     */
    private function hasFullVisibility($user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'HR']) || $user->is_dept_lead;
    }

    /**
     * List of all clients that have client-owned assets, with counts.
     * Super Admin / Admin / HR / leads see all.
     * Regular users shouldn't reach this — gated on the frontend.
     */
    public function clients(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->hasFullVisibility($user)) {
            return response()->json(['message' => 'Not authorised.'], 403);
        }

        // Pull distinct client names from assets, join to the clients table
        // where a match exists, and aggregate the counts in one query.
        $rows = Asset::select('client_name')
            ->selectRaw('COUNT(*) as total_assets')
            ->selectRaw('SUM(is_available = 1) as available_count')
            ->selectRaw('SUM(is_available = 0 AND status = "Active") as booked_count')
            ->selectRaw('SUM(status = "In Repair") as repair_count')
            ->where('ownership_type', 'Client')
            ->whereNotNull('client_name')
            ->groupBy('client_name')
            ->orderBy('client_name')
            ->get();

        // Try to match back to a proper Client record by name so we can
        // surface the client's id for linking (best-effort, not blocking).
        $clientMap = Client::whereIn('company_name', $rows->pluck('client_name'))
            ->orWhereIn('full_name', $rows->pluck('client_name'))
            ->get()
            ->keyBy(fn ($c) => $c->company_name ?: $c->full_name);

        $data = $rows->map(function ($row) use ($clientMap) {
            $client = $clientMap[$row->client_name] ?? null;
            return [
                'client_id' => $client?->id,
                'client_name' => $row->client_name,
                'total_assets' => (int) $row->total_assets,
                'available_count' => (int) $row->available_count,
                'booked_count' => (int) $row->booked_count,
                'repair_count' => (int) $row->repair_count,
            ];
        });

        return response()->json(['data' => $data, 'total' => $data->count()]);
    }

    /**
     * All assets for one client, plus their hire history.
     *
     * Full visibility users (Admin / leads / etc.) pass ?client_name=Neon+Studios.
     * Limited users (custodians) can only see assets where assigned_to = them.
     */
    public function clientAssets(Request $request): JsonResponse
    {
        $user = $request->user();
        $clientName = $request->get('client_name');

        $query = Asset::with([
            'assetCategory',
            'assignee',
            'hireRequests.requestedBy',
            'hireRequests.forUser',
            'hireRequests.approvedBy',
            'hireRequests.project.enquiry',
        ])
        ->where('ownership_type', 'Client');

        if ($this->hasFullVisibility($user)) {
            // Full visibility — can filter to a specific client or see all
            if ($clientName) {
                $query->where('client_name', $clientName);
            }
        } else {
            // Custodians only see assets assigned to them
            $query->where('assigned_to', $user->id);
            if ($clientName) {
                $query->where('client_name', $clientName);
            }
        }

        $assets = $query->orderBy('name')->get();

        $data = $assets->map(function (Asset $asset) {
            return [
                'id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'image_url' => $asset->image_url,
                'category' => $asset->assetCategory?->name ?? $asset->category,
                'subcategory' => $asset->subcategory,
                'condition' => $asset->condition,
                'status' => $asset->status,
                'is_available' => $asset->is_available,
                'assignee_name' => $asset->assignee?->name,
                'specifications' => $asset->specifications,
                'client_name' => $asset->client_name,
                'hire_history' => $asset->hireRequests
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn ($h) => [
                        'id' => $h->id,
                        'status' => $h->status,
                        'request_type' => $h->request_type,
                        'for_user_name' => $h->forUser?->name,
                        'requested_by_name' => $h->requestedBy?->name,
                        'approved_by_name' => $h->approvedBy?->name,
                        'project_title' => $h->project?->enquiry?->title,
                        'job_number' => $h->project?->enquiry?->job_number,
                        'out_date' => $h->out_date?->format('Y-m-d'),
                        'expected_return_date' => $h->expected_return_date?->format('Y-m-d'),
                        'actual_return_date' => $h->actual_return_date?->format('Y-m-d'),
                        'return_condition' => $h->return_condition,
                        'purpose' => $h->purpose,
                    ]),
            ];
        });

        return response()->json(['data' => $data]);
    }
}
