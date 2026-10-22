<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignUpdate;
use App\Models\User;
use App\Modules\Design\Requests\StoreDesignJobRequest;
use App\Modules\Design\Resources\DesignJobResource;
use App\Modules\Design\Services\DesignNotificationService;
use App\Modules\Design\Services\DesignWorkTrackingService;
use App\Modules\Design\Services\DesignProjectSyncService;
use App\Modules\Design\Support\DesignAccess;
use App\Modules\Design\Support\DesignSchedule;
use App\Support\ProjectSetupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class DesignJobController extends Controller
{
    public function __construct(
        private readonly DesignProjectSyncService $projectSyncService,
        private readonly DesignNotificationService $notifications,
        private readonly DesignWorkTrackingService $workTracking
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeLeadView($request);

        $validated = $request->validate([
            'due_within_days' => ['sometimes', 'integer', Rule::in(DesignProjectSyncService::ALLOWED_SYNC_WINDOWS)],
            'overdue_only' => ['sometimes', 'boolean'],
            'schedule_scope' => ['sometimes', Rule::in(['upcoming', 'overdue', 'all'])],
        ]);

        $setupDate = ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id');
        $scope = $request->boolean('overdue_only') ? 'overdue' : ($validated['schedule_scope'] ?? 'upcoming');
        $applyFilters = function ($q) use ($request, $validated, $setupDate, $scope) {
            if ($scope === 'overdue') {
                DesignSchedule::overdueJobs($q);
            } else {
                ProjectSetupSchedule::filterDateScope($q, $setupDate, $scope);
            }
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
                $cutoff = now('Africa/Nairobi')->startOfDay()->addDays((int) $validated['due_within_days']);
                $q->where(function ($inner) use ($cutoff, $setupDate) {
                    $inner->where($setupDate, '<=', $cutoff->toDateString())
                        ->orWhereRaw('('.$setupDate->toSql().') IS NULL', $setupDate->getBindings());
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
        $query->with(['designers' => fn ($designers) => $designers->select('users.id', 'users.name'), 'graphicMainDesigner:id,name', 'structuralMainDesigner:id,name']);
        $applyFilters($query);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobs = $query
            ->selectSub($setupDate, 'project_setup_date')
            ->orderByRaw("CASE WHEN status IN ('done', 'handed_off', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw('project_setup_date IS NULL')
            ->orderBy('project_setup_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

        $job = DesignJob::create($data);
        if ($job->project_enquiry_id) $this->projectSyncService->syncItemsForJob($job);
        $job->load(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project', 'items', 'documents', 'graphicMainDesigner:id,name', 'structuralMainDesigner:id,name']);
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
            'graphicMainDesigner:id,name', 'structuralMainDesigner:id,name',
            'items.type',
            'items.assignedUser:id,name',
            'items.notRequiredActor:id,name',
            'items.printMaterial',
            'items.documents',
            'items.bomItems.material.baseUom',
            'items.handoffs',
            'items.workSessions.user:id,name',
            'items.workSessions.endedBy:id,name',
            'documents',
        ]);

        return response()->json(['data' => new DesignJobResource($job)]);
    }

    public function update(StoreDesignJobRequest $request, DesignJob $job): JsonResponse
    {
        $this->authorizeLeadView($request);

        $data = $request->validated();
        $data['updated_by'] = auth()->id();

        DB::transaction(function () use ($job, $data) {
            $job->update($data);
            if ($job->status === 'cancelled') {
                foreach ($job->items()->get() as $item) {
                    $this->workTracking->closeForWorkflow($item, 'cancelled', 'design_job_cancelled');
                }
            }
        });
        $job->load(['enquiry.client', 'enquiry.deliverables', 'enquiry.projectOfficer', 'project', 'items', 'documents', 'graphicMainDesigner:id,name', 'structuralMainDesigner:id,name']);

        return response()->json([
            'message' => 'Design job updated successfully',
            'data' => new DesignJobResource($job),
        ]);
    }

    public function updateMainDesigners(Request $request, DesignJob $job): JsonResponse
    {
        $this->authorizeLeadView($request);
        $data = $request->validate([
            'graphic_main_designer_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
            'structural_main_designer_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);
        foreach (['graphic_main_designer_id', 'structural_main_designer_id'] as $field) {
            if ($data[$field] === null) continue;
            $user = User::findOrFail($data[$field]);
            if (! $user->is_active || ! $user->hasRole('Designer')) {
                throw ValidationException::withMessages([$field => ['Select an active designer.']]);
            }
        }

        $previous = ['graphic' => $job->graphic_main_designer_id, 'structural' => $job->structural_main_designer_id];
        $counts = DB::transaction(function () use ($job, $data, $request) {
            $job = DesignJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $counts = ['graphic' => 0, 'structural' => 0];
            $finished = ['done', 'cancelled', 'print_ready', 'production_ready', 'handed_off', 'not_required'];
            foreach (['graphic' => 'graphic_main_designer_id', 'structural' => 'structural_main_designer_id'] as $stream => $field) {
                $newId = $data[$field];
                if ($newId == $job->{$field}) continue;
                $items = DesignItem::query()->where('design_job_id', $job->id)->where('stream', $stream)
                    ->whereNotIn('status', $finished)
                    ->whereNull('outsourced_partner_name')
                    ->whereNotNull('generated_scope_key')
                    ->where('assignment_source', 'project_default')
                    ->lockForUpdate()->get();
                foreach ($items as $item) {
                    if ($item->assigned_to == $newId && $item->assignment_source === 'project_default') continue;
                    $oldName = $item->assignedUser?->name ?? 'Unassigned';
                    if ($item->workSessions()->whereNull('ended_at')->exists()) {
                        $this->workTracking->closeForWorkflow($item, 'paused', 'switched_task');
                    }
                    $item->update(['assigned_to' => $newId, 'assignment_source' => 'project_default', 'updated_by' => $request->user()->id]);
                    DesignUpdate::create([
                        'design_item_id' => $item->id,
                        'note' => "Project {$stream} designer changed from {$oldName} to ".($newId ? User::find($newId)?->name : 'Unassigned').'.',
                        'created_by' => $request->user()->id,
                    ]);
                    $counts[$stream]++;
                }
                $job->{$field} = $newId;
            }
            $job->updated_by = $request->user()->id;
            $job->save();

            return $counts;
        });

        $job = $job->fresh(['graphicMainDesigner:id,name', 'structuralMainDesigner:id,name', 'items.assignedUser:id,name']);
        foreach (['graphic' => 'graphic_main_designer_id', 'structural' => 'structural_main_designer_id'] as $stream => $field) {
            if ($previous[$stream] != $job->{$field}) {
                $this->notifications->notifyProjectDesignerChanged($job, $stream, $previous[$stream], $job->{$field}, $counts[$stream]);
            }
        }
        return response()->json(['message' => 'Project designers updated.', 'data' => new DesignJobResource($job), 'assignment_counts' => $counts]);
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

        $job = $this->projectSyncService->syncFromEnquiry($enquiry);

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
