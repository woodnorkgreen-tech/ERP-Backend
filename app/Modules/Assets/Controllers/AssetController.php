<?php

namespace App\Modules\Assets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Requests\StoreAssetRequest;
use App\Modules\Assets\Requests\UpdateAssetRequest;
use App\Modules\Assets\Resources\AssetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    /**
     * Display a listing of the assets.
     */
    public function index(Request $request): JsonResponse
    {
        $paginated = $this->buildAssetQuery($request)->paginate($request->get('per_page', 50));

        $stats = [
            'total_assets' => (int) Asset::active()->count(),
            'company_owned_count' => (int) Asset::active()->byOwnership('Company')->count(),
            'client_owned_count' => (int) Asset::active()->byOwnership('Client')->count(),
            'available_count' => (int) Asset::active()->where('is_available', true)->count(),
            'unavailable_count' => (int) Asset::active()->where('is_available', false)->count(),
        ];

        return response()->json([
            'data' => AssetResource::collection($paginated)->resolve(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'stats' => $stats,
        ]);
    }

    /**
     * Report 79B — what an asset picker offers.
     *
     * The hire-request forms used to load the first 200 available assets with
     * no search, so asset #201 never appeared, and an asset marked Retired or
     * In Repair but still flagged available was offered as ready. This is
     * searched on the server (every typed word, any order), paged, and says
     * of each asset whether it can be requested now and, if not, why. It only
     * reads: choosing an asset creates nothing until a hire or assign request
     * goes through its own approval.
     */
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'offerable_only' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = Asset::query()
            ->active()
            ->with(['assetCategory:id,name', 'assignee:id,name', 'activeHireRequest.forUser:id,name'])
            ->withCount(['hireRequests as pending_requests_count' => fn ($q) => $q->where('status', \App\Modules\Assets\Models\AssetHireRequest::STATUS_PENDING)]);

        $words = app(\App\Modules\MaterialsLibrary\Services\MaterialDuplicateFinder::class)
            ->tokens((string) ($validated['search'] ?? ''));
        foreach ($words as $word) {
            $query->where(function ($match) use ($word) {
                foreach (['name', 'asset_code', 'category', 'subcategory', 'location', 'serial_number', 'manufacturer', 'model'] as $column) {
                    $match->orWhere($column, 'like', "%{$word}%");
                }
            });
        }

        if ($request->boolean('offerable_only')) {
            $query->where('status', 'Active')->where('is_available', true);
        }

        $page = $query
            // Ready to request first, then by name, so the useful rows lead.
            ->orderByRaw("CASE WHEN status = 'Active' AND is_available = 1 THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Asset $asset) => $this->option($asset))->values(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /** One picker row: identity, condition, whereabouts, and whether it can be requested now. */
    private function option(Asset $asset): array
    {
        $holder = $asset->activeHireRequest?->forUser?->name ?? $asset->assignee?->name;
        $reason = match (true) {
            $asset->status !== 'Active' => "{$asset->status}: not in service",
            ! $asset->is_available => $holder ? "Out with {$holder}" : 'Not available',
            default => null,
        };

        return [
            'id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category' => $asset->assetCategory?->name ?? $asset->category,
            'ownership_type' => $asset->ownership_type,
            'status' => $asset->status,
            'condition' => $asset->condition,
            'is_available' => (bool) $asset->is_available,
            'location' => $asset->location,
            'holder' => $holder,
            'expected_return_date' => $asset->activeHireRequest?->expected_return_date?->toDateString(),
            'pending_requests' => (int) $asset->pending_requests_count,
            'offerable' => $reason === null,
            'unavailable_reason' => $reason,
        ];
    }

    /**
     * Shared query builder used by index().
     */
    private function buildAssetQuery(Request $request)
    {
        $query = Asset::with(['assignee', 'department', 'assetCategory']);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        } else {
            $query->active();
        }

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('category_id')) {
            $query->byCategoryId($request->category_id);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('ownership_type')) {
            $query->byOwnership($request->ownership_type);
        }

        if ($request->has('is_available') && $request->filled('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }

        // Service filters
        if ($request->filled('service_filter')) {
            $today = now()->toDateString();
            $soon  = now()->addDays(7)->toDateString();
            if ($request->service_filter === 'overdue') {
                $query->whereNotNull('next_service_date')->where('next_service_date', '<', $today);
            } elseif ($request->service_filter === 'due_soon') {
                $query->whereNotNull('next_service_date')
                    ->where('next_service_date', '>=', $today)
                    ->where('next_service_date', '<=', $soon);
            }
        }

        if ($request->filled('department_id')) {
            $query->byDepartment($request->department_id);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->filled('search')) {
            $query->search((string) $request->search);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedColumns = ['name', 'asset_code', 'category', 'status', 'purchase_cost_kes', 'current_value', 'created_at'];

        if (in_array($sortBy, $allowedColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest();
        }

        return $query;
    }

    /**
     * Store a newly created asset in storage.
     */
    public function store(StoreAssetRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);

        $data['asset_code'] = $data['asset_code'] ?? Asset::generateAssetCode($data['category_id'] ?? null);
        $data['status'] = $data['status'] ?? 'Active';
        $data['qty'] = $data['qty'] ?? 1;
        $data['is_available'] = $request->boolean('is_available', true);

        // If a client_id was sent instead of a raw client_name, resolve the
        // name from the Client model so both are always kept in sync.
        if (!empty($data['client_id'])) {
            $client = \App\Modules\ClientService\Models\Client::find($data['client_id']);
            if ($client) {
                $data['client_name'] = $client->company_name ?: $client->full_name;
            }
            unset($data['client_id']); // not a real column on assets
        }
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data = $this->syncCategoryName($data);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('assets/photos', 'public');
        }

        $asset = Asset::create($data);
        $asset->load(['assignee', 'department', 'assetCategory']);

        return response()->json([
            'message' => 'Asset created successfully',
            'data' => new AssetResource($asset),
        ], 201);
    }

    /**
     * Display the specified asset.
     */
    public function show($id): JsonResponse
    {
        $asset = Asset::with([
            'assignee', 'department', 'assetCategory', 'creator', 'updater',
            'activeHireRequest.forUser', 'activeHireRequest.project.enquiry',
            'assignmentHistory.heldBy', 'assignmentHistory.assignedBy',
        ])->findOrFail($id);

        return response()->json([
            'data' => new AssetResource($asset),
        ]);
    }

    /**
     * Update the specified asset in storage.
     */
    public function update(UpdateAssetRequest $request, $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $data = $request->validated();
        unset($data['image']);
        $data['updated_by'] = auth()->id();
        $data = $this->syncCategoryName($data);

        if (!empty($data['client_id'])) {
            $client = \App\Modules\ClientService\Models\Client::find($data['client_id']);
            if ($client) {
                $data['client_name'] = $client->company_name ?: $client->full_name;
            }
            unset($data['client_id']);
        }

        if ($request->boolean('is_available') !== null && $request->has('is_available')) {
            $data['is_available'] = $request->boolean('is_available');
        }

        if ($request->hasFile('image')) {
            if ($asset->image_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($asset->image_path);
            }
            $data['image_path'] = $request->file('image')->store('assets/photos', 'public');
        }

        $asset->update($data);
        $asset->load(['assignee', 'department', 'assetCategory']);

        return response()->json([
            'message' => 'Asset updated successfully',
            'data' => new AssetResource($asset),
        ]);
    }

    /**
     * Quickly toggle an asset's availability — the action the employee
     * "in charge" (assigned_to) uses day-to-day, without opening the full edit form.
     */
    public function toggleAvailability(Request $request, $id): JsonResponse
    {
        $request->validate([
            'is_available' => 'required|boolean',
        ]);

        $asset = Asset::findOrFail($id);
        $asset->update([
            'is_available' => $request->boolean('is_available'),
            'updated_by' => auth()->id(),
        ]);
        $asset->load(['assignee', 'department', 'assetCategory']);

        return response()->json([
            'message' => $asset->is_available ? 'Asset marked as available' : 'Asset marked as unavailable',
            'data' => new AssetResource($asset),
        ]);
    }

    /**
     * When category_id is provided, copy its name(s) into the legacy
     * `category`/`subcategory` string columns too — same dual-write
     * pattern MaterialsLibrary uses for its category tree, so any
     * older code reading the plain string columns keeps working.
     * If the chosen category has a parent, the parent's name becomes
     * `category` and the chosen one becomes `subcategory`; otherwise
     * the chosen one is the (root-level) `category`.
     */
    private function syncCategoryName(array $data): array
    {
        if (empty($data['category_id'])) {
            return $data;
        }

        $category = AssetCategory::with('parent')->find($data['category_id']);
        if (!$category) {
            return $data;
        }

        if ($category->parent) {
            $data['category'] = $data['category'] ?? $category->parent->name;
            $data['subcategory'] = $data['subcategory'] ?? $category->name;
        } else {
            $data['category'] = $data['category'] ?? $category->name;
        }

        return $data;
    }

    /**
     * Soft-delete several assets at once — mainly for cleaning up import
     * mistakes (e.g. an accidental re-import before the dedupe fix landed).
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $count = Asset::whereIn('id', $validated['ids'])->count();
        Asset::whereIn('id', $validated['ids'])->delete();

        return response()->json([
            'message' => "{$count} asset(s) deleted",
            'data' => ['deleted' => $count],
        ]);
    }

    /**
     * Remove the specified asset from storage (soft delete).
     */
    public function destroy($id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        return response()->json([
            'message' => 'Asset deleted successfully',
        ]);
    }

    /**
     * Display a listing of trashed assets.
     */
    public function trashed(Request $request): JsonResponse
    {
        $query = Asset::onlyTrashed()->with(['assignee', 'department']);

        if ($request->filled('search')) {
            $query->search((string) $request->search);
        }

        $assets = $query->latest()->get();

        return response()->json([
            'data' => AssetResource::collection($assets),
        ]);
    }

    /**
     * Restore a trashed asset.
     */
    public function restore($id): JsonResponse
    {
        $asset = Asset::withTrashed()->findOrFail($id);
        $asset->restore();

        return response()->json([
            'message' => 'Asset restored successfully',
            'data' => new AssetResource($asset),
        ]);
    }
}
