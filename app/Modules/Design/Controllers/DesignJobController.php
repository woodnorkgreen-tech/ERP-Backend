<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Requests\StoreDesignJobRequest;
use App\Modules\Design\Resources\DesignJobResource;
use App\Modules\Design\Services\DesignNotificationService;
use App\Modules\Design\Services\DesignProjectSyncService;
use App\Modules\Design\Support\DesignAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DesignJobController extends Controller
{
    public function __construct(
        private readonly DesignProjectSyncService $projectSyncService,
        private readonly DesignNotificationService $notifications
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeLeadView($request);

        $validated = $request->validate([
            'due_within_days' => ['sometimes', 'integer', Rule::in(DesignProjectSyncService::ALLOWED_SYNC_WINDOWS)],
        ]);

        $applyFilters = function ($q) use ($request, $validated) {
            if ($request->filled('project_enquiry_id')) {
                $q->where('project_enquiry_id', $request->project_enquiry_id);
            }

            if ($request->filled('search')) {
                $search = $request->string('search');
                $q->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('job_number', 'like', "%{$search}%")
                        ->orWhereHas('enquiry', fn ($eq) => $eq->where('enquiry_number', 'like', "%{$search}%"));
                });
            }

            if (!empty($validated['due_within_days'])) {
                $cutoff = now()->startOfDay()->addDays((int) $validated['due_within_days']);
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNull('due_date')->orWhereDate('due_date', '<=', $cutoff);
                });
            }
        };

        // Status counts reflect search/window filters but not the active status
        // tab itself, so switching tabs doesn't change the other tabs' counts.
        // Built from an independent query (not the eager-loaded one below) so
        // withCount's injected column doesn't pollute this aggregate select.
        $countsQuery = DesignJob::query();
        $applyFilters($countsQuery);
        $counts = $countsQuery->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $query = DesignJob::withCount('items')->with(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project']);
        $applyFilters($query);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobs = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->paginate(
                $request->get('per_page', 25),
                ['*'],
                'page',
                $request->get('page', 1)
            );

        return response()->json([
            'data' => DesignJobResource::collection($jobs)->resolve(),
            'current_page' => $jobs->currentPage(),
            'last_page' => $jobs->lastPage(),
            'total' => $jobs->total(),
            'per_page' => $jobs->perPage(),
            'counts' => [
                'all' => (int) $counts->sum(),
                'pending' => (int) $counts->get('pending', 0),
                'in_design' => (int) $counts->get('in_design', 0),
                'done' => (int) $counts->get('done', 0),
                'cancelled' => (int) $counts->get('cancelled', 0),
            ],
        ]);
    }

    public function store(StoreDesignJobRequest $request): JsonResponse
    {
        $this->authorizeLeadView($request);

        $data = $request->validated();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $job = DesignJob::create($data)->load(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project', 'items', 'documents']);
        $this->notifications->notifyJobSynced($job);

        return response()->json([
            'message' => 'Design job created successfully',
            'data' => new DesignJobResource($job),
        ], 201);
    }

    public function show(Request $request, DesignJob $job): JsonResponse
    {
        $this->authorizeLeadView($request);

        $job->load([
            'enquiry.client',
            'enquiry.deliverables',
            'enquiry.projectOfficer',
            'project',
            'items.type',
            'items.printMaterial',
            'items.documents',
            'items.bomItems.material.baseUom',
            'items.handoffs',
            'documents',
        ]);

        return response()->json(['data' => new DesignJobResource($job)]);
    }

    public function update(StoreDesignJobRequest $request, DesignJob $job): JsonResponse
    {
        $this->authorizeLeadView($request);

        $data = $request->validated();
        $data['updated_by'] = auth()->id();

        $job->update($data);
        $job->load(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project', 'items', 'documents']);

        return response()->json([
            'message' => 'Design job updated successfully',
            'data' => new DesignJobResource($job),
        ]);
    }

    public function syncUpcoming(Request $request): JsonResponse
    {
        $this->authorizeLeadView($request);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', Rule::in(DesignProjectSyncService::ALLOWED_SYNC_WINDOWS)],
        ]);

        $result = $this->projectSyncService->syncAllUpcoming($validated['days'] ?? null);

        return response()->json([
            'message' => $result['created'] > 0
                ? "{$result['created']} new design job(s) created from projects due in the next {$result['days']} days."
                : "No new design jobs to create. Projects due in the next {$result['days']} days are already up to date.",
            'created' => $result['created'],
            'total' => $result['total'],
            'days' => $result['days'],
        ]);
    }

    public function syncFromProject(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $this->authorizeLeadView($request);

        $enquiry->loadMissing(['client', 'project', 'deliverables', 'projectOfficer']);

        $job = DesignJob::updateOrCreate(
            ['project_enquiry_id' => $enquiry->id],
            [
                'project_id' => $enquiry->project?->id,
                'client_id' => $enquiry->client_id,
                'job_number' => $enquiry->job_number,
                'title' => $enquiry->title ?? "Design Job #{$enquiry->id}",
                'source_type' => 'project_scope',
                'sync_origin' => 'manual',
                'priority' => $this->projectSyncService->mapPriority($enquiry->priority),
                'due_date' => $enquiry->expected_delivery_date,
                'status' => 'pending',
                'updated_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]
        );

        if ($job->wasRecentlyCreated) {
            $this->notifications->notifyJobSynced($job->loadMissing(['enquiry.client']));
        }

        return response()->json([
            'message' => 'Design job synced from project successfully',
            'data' => new DesignJobResource($job->load(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project', 'items', 'documents'])),
        ]);
    }

    private function authorizeLeadView(Request $request): void
    {
        abort_unless(DesignAccess::userCanAccessLeadViews($request->user()), 403, 'Only Design leads can manage Design jobs.');
    }
}
