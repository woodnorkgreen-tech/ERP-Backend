<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Requests\StoreDesignItemRequest;
use App\Modules\Design\Resources\DesignItemResource;
use App\Modules\Design\Services\DesignHandoffService;
use App\Modules\Design\Services\DesignItemReadinessService;
use App\Modules\Design\Services\DesignNotificationService;
use App\Modules\Design\Services\DesignRedesignService;
use App\Modules\Design\Services\DimensionConversionService;
use App\Modules\Design\Services\DesignWorkTrackingService;
use App\Modules\Design\Support\DesignSchedule;
use App\Modules\Design\Support\DesignPauseReason;
use App\Modules\Design\Support\DesignAccess;
use App\Modules\Printing\Services\PrintIntakeService;
use App\Support\ProjectSetupSchedule;
use Illuminate\Http\JsonResponse;
use App\Modules\Design\Models\DesignUpdate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class DesignItemController extends Controller
{
    private const HISTORY_RELATIONS = [
        'revisions.documents', 'revisions.creator:id,name', 'revisions.approver:id,name',
        'changeRequests.againstRevision', 'changeRequests.addressedByRevision', 'changeRequests.recorder:id,name',
        'updates.creator:id,name',
    ];

    public function __construct(
        private readonly DimensionConversionService $dimensions,
        private readonly DesignItemReadinessService $readiness,
        private readonly DesignHandoffService $handoffs,
        private readonly PrintIntakeService $printingIntake,
        private readonly DesignNotificationService $notifications,
        private readonly DesignRedesignService $redesigns,
        private readonly DesignWorkTrackingService $workTracking
    ) {
    }

    public function designers(): JsonResponse
    {
        $designers = User::whereHas('roles', fn ($q) => $q->where('name', 'Designer'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $designers]);
    }

    public function index(Request $request, string $stream): JsonResponse
    {
        $request->validate([
            'overdue_only' => ['sometimes', 'boolean'],
            'schedule_scope' => ['sometimes', Rule::in(['upcoming', 'overdue', 'all'])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'mine' => ['sometimes', 'boolean'],
        ]);
        $scope = $request->boolean('overdue_only') ? 'overdue' : $request->input('schedule_scope', 'upcoming');
        $query = DesignItem::where('stream', $stream);
        $myActiveQuery = DesignItem::query()
            ->where('stream', $stream)
            ->where('assigned_to', auth()->id())
            ->whereNotIn('status', ['done', 'cancelled', 'print_ready', 'production_ready', 'handed_off', 'not_required']);
        if ($scope === 'overdue') {
            DesignSchedule::overdueItems($myActiveQuery);
        } else {
            ProjectSetupSchedule::filterDateScope($myActiveQuery, DesignSchedule::itemDateQuery(), $scope);
        }
        $myActiveCount = $myActiveQuery->count();
        $myWorkApplied = $request->boolean('mine') && $myActiveCount > 0;

        if ($myWorkApplied) {
            $query->where('assigned_to', auth()->id());
        }

        if ($scope === 'overdue') {
            DesignSchedule::overdueItems($query);
        } else {
            ProjectSetupSchedule::filterDateScope($query, DesignSchedule::itemDateQuery(), $scope);
        }

        if ($request->boolean('outsourced_only')) {
            $query->whereNotNull('outsourced_partner_name');
        }

        if ($request->filled('design_job_id')) {
            $query->where('design_job_id', $request->design_job_id);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($match) => $match
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('job', fn ($job) => $job->where('title', 'like', "%{$search}%"))
                ->orWhereHas('type', fn ($type) => $type->where('name', 'like', "%{$search}%"))
                ->orWhereHas('assignedUser', fn ($user) => $user->where('name', 'like', "%{$search}%")));
        }

        $counts = (clone $query)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $status = $request->input('status', $request->route('status'));
        if ($status) {
            $query->where('status', $status);
        } else {
            $query->where('status', '!=', 'not_required');
        }

        $items = $query->select('design_items.*')->selectSub(DesignSchedule::itemDateQuery(), 'project_setup_date')
            ->with([
                'job' => fn ($job) => $job->select('design_jobs.*')->selectSub(
                    ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id'), 'project_setup_date'),
                'job.enquiry.deliverables', 'job.documents', 'assignedUser:id,name', 'notRequiredActor:id,name', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs',
                'workSessions.user:id,name', 'workSessions.endedBy:id,name',
                ...self::HISTORY_RELATIONS,
            ])
            ->orderByRaw("CASE WHEN status IN ('done', 'print_ready', 'production_ready', 'handed_off', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw('project_setup_date IS NULL')->orderBy('project_setup_date')
            ->latest()->orderByDesc('id')->paginate($request->get('per_page', 25));

        return response()->json([
            'data' => DesignItemResource::collection($items)->resolve(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
            'per_page' => $items->perPage(),
            'counts' => array_merge($counts->map(fn ($count) => (int) $count)->all(), ['all' => (int) ($counts->sum() - $counts->get('not_required', 0))]),
            'my_work_active_count' => $myActiveCount,
            'my_work_applied' => $myWorkApplied,
        ]);
    }

    public function bundles(Request $request, string $stream): JsonResponse
    {
        $request->validate([
            'overdue_only' => ['sometimes', 'boolean'],
            'schedule_scope' => ['sometimes', Rule::in(['upcoming', 'overdue', 'all'])],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'mine' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $scope = $request->boolean('overdue_only') ? 'overdue' : $request->input('schedule_scope', 'upcoming');

        $myActiveQuery = DesignItem::query()
            ->where('stream', $stream)
            ->where('assigned_to', auth()->id())
            ->whereNotIn('status', ['done', 'cancelled', 'print_ready', 'production_ready', 'handed_off', 'not_required']);
        if ($scope === 'overdue') {
            DesignSchedule::overdueItems($myActiveQuery);
        } else {
            ProjectSetupSchedule::filterDateScope($myActiveQuery, DesignSchedule::itemDateQuery(), $scope);
        }
        $myActiveCount = $myActiveQuery->count();
        $myWorkApplied = $request->boolean('mine') && $myActiveCount > 0;

        $candidateQuery = DesignItem::query()->where('stream', $stream);
        if ($myWorkApplied) {
            $candidateQuery->where('assigned_to', auth()->id());
        }
        if ($scope === 'overdue') {
            DesignSchedule::overdueItems($candidateQuery);
        } else {
            ProjectSetupSchedule::filterDateScope($candidateQuery, DesignSchedule::itemDateQuery(), $scope);
        }
        if ($request->boolean('outsourced_only')) {
            $candidateQuery->whereNotNull('outsourced_partner_name');
        }

        if ($request->filled('design_job_id')) {
            $candidateQuery->where('design_job_id', $request->integer('design_job_id'));
        }
        if ($request->filled('search')) {
            $search = (string) $request->get('search');
            $candidateQuery->where(fn ($match) => $match
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('job', fn ($job) => $job->where('title', 'like', "%{$search}%")->orWhere('job_number', 'like', "%{$search}%"))
                ->orWhereHas('type', fn ($type) => $type->where('name', 'like', "%{$search}%"))
                ->orWhereHas('assignedUser', fn ($user) => $user->where('name', 'like', "%{$search}%")));
        }

        $counts = (clone $candidateQuery)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        if ($request->filled('status')) {
            $candidateQuery->where('status', $request->string('status'));
        } else {
            $candidateQuery->where('status', '!=', 'not_required');
        }

        $candidates = $candidateQuery
            ->select('design_items.id', 'design_items.design_job_id')
            ->selectSub(DesignSchedule::itemDateQuery(), 'project_setup_date')
            ->with('job:id,project_id,project_enquiry_id,title,job_number')
            ->orderByRaw('project_setup_date IS NULL')
            ->orderBy('project_setup_date')
            ->get();

        $groups = $candidates->groupBy(fn (DesignItem $item) => $this->bundleKey($item));
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 25)));
        $groupKeys = $groups->keys()->values();
        $selectedKeys = $groupKeys->slice(($page - 1) * $perPage, $perPage)->values();
        $selectedDescriptors = $selectedKeys->map(fn (string $key) => $this->bundleDescriptor($key));

        $jobIds = $selectedDescriptors->isEmpty() ? collect() : DesignJob::query()
            ->where(function ($query) use ($selectedDescriptors) {
                foreach ($selectedDescriptors as $descriptor) {
                    $query->orWhere(function ($match) use ($descriptor) {
                        if ($descriptor['type'] === 'project') {
                            $match->where('project_id', $descriptor['id']);
                        } elseif ($descriptor['type'] === 'enquiry') {
                            $match->whereNull('project_id')->where('project_enquiry_id', $descriptor['id']);
                        } else {
                            $match->whereKey($descriptor['id']);
                        }
                    });
                }
            })
            ->pluck('id');

        $allItems = DesignItem::query()
            ->where('stream', $stream)
            ->whereIn('design_job_id', $jobIds)
            ->when($request->input('status') === 'not_required',
                fn ($query) => $query->where('status', 'not_required'),
                fn ($query) => $query->where('status', '!=', 'not_required'))
            ->select('design_items.*')
            ->selectSub(DesignSchedule::itemDateQuery(), 'project_setup_date')
            ->with([
                'job' => fn ($job) => $job->select('design_jobs.*')->selectSub(
                    ProjectSetupSchedule::dateQuery('design_jobs.project_enquiry_id', 'design_jobs.project_id'), 'project_setup_date'),
                'job.enquiry.client', 'job.enquiry.deliverables', 'job.documents', 'assignedUser:id,name', 'notRequiredActor:id,name', 'type',
                'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs',
                'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS,
            ])
            ->orderByRaw("CASE WHEN status IN ('done', 'print_ready', 'production_ready', 'handed_off', 'cancelled') THEN 1 ELSE 0 END")
            ->latest()
            ->get()
            ->groupBy(fn (DesignItem $item) => $this->bundleKey($item));

        $completedStatuses = DesignItem::COMPLETED_STATUSES;
        $workingStatuses = ['in_design'];
        $waitingStatuses = ['pending', 'awaiting_client_approval', 'client_changes_requested'];
        $data = $selectedKeys->map(function (string $key) use ($allItems, $groups, $completedStatuses, $workingStatuses, $waitingStatuses) {
            $items = $allItems->get($key, collect())->values();
            $first = $items->first();
            $total = $items->where('status', '!=', 'not_required')->count();
            $completed = $items->whereIn('status', $completedStatuses)->count();
            $matchingIds = $groups->get($key, collect())->pluck('id')->values();

            return [
                'key' => $key,
                'project_id' => $first?->job?->project_id,
                'project_enquiry_id' => $first?->job?->project_enquiry_id,
                'job_number' => $first?->job?->job_number,
                'project_name' => $first?->job?->enquiry?->title ?? $first?->job?->title ?? 'Unlinked design work',
                'client_name' => $first?->job?->enquiry?->client?->full_name ?? $first?->job?->enquiry?->client?->name,
                'project_setup_date' => $first?->job?->project_setup_date,
                'total_elements' => $total,
                'matching_elements' => $matchingIds->count(),
                'matching_item_ids' => $matchingIds,
                'completed_elements' => $completed,
                'working_elements' => $items->whereIn('status', $workingStatuses)->count(),
                'waiting_elements' => $items->whereIn('status', $waitingStatuses)->count(),
                'progress_percent' => $total ? (int) round(($completed / $total) * 100) : 0,
                'needs_attention' => $items->where('status', 'client_changes_requested')->isNotEmpty()
                    || $items->contains(fn (DesignItem $item) => $item->handoffs->contains('status', 'rejected')),
                'items' => DesignItemResource::collection($items)->resolve(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($groupKeys->count() / $perPage)),
            'total' => $groupKeys->count(),
            'per_page' => $perPage,
            'counts' => array_merge($counts->map(fn ($count) => (int) $count)->all(), ['all' => (int) ($counts->sum() - $counts->get('not_required', 0))]),
            'my_work_active_count' => $myActiveCount,
            'my_work_applied' => $myWorkApplied,
        ]);
    }

    private function bundleKey(DesignItem $item): string
    {
        if ($item->job?->project_id) return 'project:' . $item->job->project_id;
        if ($item->job?->project_enquiry_id) return 'enquiry:' . $item->job->project_enquiry_id;

        return 'job:' . $item->design_job_id;
    }

    /**
     * Outsourcing a design item is open to anyone in design (creatives.view, held by the Designer role).
     * Clearing it or assigning an internal designer does not need the check.
     */
    private function applyOutsourcing(StoreDesignItemRequest $request, array $data): array
    {
        if (array_key_exists('outsourced', $data)) {
            abort_unless($request->user()?->can(\App\Constants\Permissions::CREATIVES_VIEW), 403);
        }

        return \App\Support\Outsourcing\OutsourcingAssignment::apply($data, 'assigned_to', $request->user()?->id);
    }

    /**
     * Write a design history note when the outsourcing partner or reason changed on the last save.
     * Uses the item's own change tracking, so unrelated edits add no history.
     */
    private function recordOutsourcingChange(DesignItem $item): void
    {
        if (! $item->wasRecentlyCreated && ! $item->wasChanged(['outsourced_partner_name', 'outsourced_reason'])) {
            return;
        }

        DesignUpdate::create([
            'design_item_id' => $item->id,
            'note' => \App\Support\Outsourcing\OutsourcingAssignment::historyNote(
                $item->outsourced_partner_name,
                $item->outsourced_reason,
                $item->outsourced_reason_note,
            ),
            'created_by' => auth()->id(),
        ]);
    }

    private function bundleDescriptor(string $key): array
    {
        [$type, $id] = explode(':', $key, 2);

        return ['type' => $type, 'id' => (int) $id];
    }

    public function store(StoreDesignItemRequest $request, DesignJob $job, string $stream): JsonResponse
    {
        $data = $this->applyOutsourcing($request, $this->dimensions->normalize($request->validated()));
        if (array_key_exists('outsourced', $data) && $data['outsourced']) {
            $data['assignment_source'] = 'individual';
        }
        $data['stream'] = $stream;
        $data['destination'] = $data['destination'] ?? ($stream === DesignItem::STREAM_GRAPHIC ? 'printing' : 'production');
        $data['design_job_id'] = $job->id;
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $item = DesignItem::create($data);
        if ($item->outsourced_partner_name !== null) {
            $this->recordOutsourcingChange($item);
        }
        $item->load(['job.documents', 'assignedUser:id,name', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS]);

        $this->notifications->notifyItemAssigned($item);

        return response()->json([
            'message' => 'Design item created successfully',
            'data' => new DesignItemResource($item),
        ], 201);
    }

    public function update(StoreDesignItemRequest $request, DesignItem $item): JsonResponse
    {
        if ($item->status === 'not_required' && $request->input('status') !== 'not_required' && $request->has('status')) {
            throw ValidationException::withMessages(['status' => ['Restore this task before changing its status.']]);
        }
        $previousAssignedTo = $item->assigned_to;
        $previousStatus = $item->status;

        $data = $this->applyOutsourcing($request, $this->dimensions->normalize($request->validated()));
        $data['updated_by'] = auth()->id();
        if (($data['outsourced'] ?? false) || ! empty($data['outsourced_partner_name'])) {
            $data['assignment_source'] = 'individual';
        }
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] != $previousAssignedTo) {
            $data['assignment_source'] = 'individual';
        }

        if (array_key_exists('status', $data) && $data['status'] !== 'print_ready' && $previousStatus === 'print_ready') {
            $data['print_ready_at'] = null;
        }

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] != $previousAssignedTo) {
            $this->workTracking->closeForWorkflow($item, 'paused', 'switched_task');
        }
        $item->update($data);
        $this->recordOutsourcingChange($item);

        if (array_key_exists('status', $data) && $data['status'] !== $previousStatus) {
            if ($data['status'] === 'awaiting_client_approval') {
                $this->workTracking->closeForWorkflow($item, 'paused', 'waiting_client_feedback');
            } elseif (in_array($data['status'], ['done', 'cancelled', 'print_ready', 'production_ready'], true)) {
                $this->workTracking->closeForWorkflow($item, 'completed');
            }
        }
        $item->load(['job.documents', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs', 'assignedUser', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS]);

        if ($item->stream === DesignItem::STREAM_GRAPHIC) {
            if ($previousStatus === 'print_ready' && $item->status !== 'print_ready') {
                $this->handoffs->cancelPrintingQueueForChanges($item);
                $item->load('handoffs');
            } elseif ($item->status === 'print_ready') {
                $handoff = $this->handoffs->createPrintingHandoffOnce($item);
                $this->printingIntake->accept($handoff);
                $item->load('handoffs');
            }
        }

        if ($item->assigned_to && $item->assigned_to !== $previousAssignedTo) {
            $this->notifications->notifyItemAssigned($item);
        }

        if ($item->assigned_to != $previousAssignedTo) {
            DesignUpdate::create([
                'design_item_id' => $item->id,
                'note' => 'Designer changed from '.($previousAssignedTo ? User::find($previousAssignedTo)?->name : 'Unassigned').' to '.($item->assignedUser?->name ?? 'Unassigned').'.',
                'created_by' => auth()->id(),
            ]);
        }

        return response()->json([
            'message' => 'Design item updated successfully',
            'data' => new DesignItemResource($item),
        ]);
    }

    public function destroy(DesignItem $item): JsonResponse
    {
        if ($item->generated_scope_key !== null) {
            throw ValidationException::withMessages(['item' => ['Project element tasks cannot be deleted. Mark the task Not required instead.']]);
        }
        $item->delete();

        return response()->json(['message' => 'Design item deleted successfully']);
    }

    public function markNotRequired(Request $request, DesignItem $item): JsonResponse
    {
        $this->authorizeScopeDecision($request, $item);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $item = DB::transaction(function () use ($item, $data, $request) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status === 'not_required') return $item;
            if ($item->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['Cancelled work cannot be marked Not required.']]);
            }
            if ($item->handoffs()->whereIn('status', ['pending', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['status' => ['Resolve the downstream handoff before marking this task Not required.']]);
            }
            if (($item->status !== 'pending' || $item->workSessions()->exists()) && blank($data['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => ['Explain why this task is no longer required.']]);
            }
            $this->workTracking->closeForWorkflow($item, 'paused', 'not_required');
            $item->update([
                'status' => 'not_required', 'not_required_by' => $request->user()->id,
                'not_required_at' => now(), 'not_required_reason' => $data['reason'] ?? null,
                'updated_by' => $request->user()->id,
            ]);
            DesignUpdate::create([
                'design_item_id' => $item->id,
                'note' => 'Marked Not required'.(filled($data['reason'] ?? null) ? ': '.$data['reason'] : '.'),
                'created_by' => $request->user()->id,
            ]);
            return $item;
        });

        return response()->json(['message' => 'Design task marked Not required.', 'data' => new DesignItemResource($item->fresh(['job', 'assignedUser:id,name', 'notRequiredActor:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS]))]);
    }

    public function restoreRequired(Request $request, DesignItem $item): JsonResponse
    {
        $this->authorizeScopeDecision($request, $item);
        $item = DB::transaction(function () use ($item, $request) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'not_required') return $item;
            $mainId = $item->stream === 'graphic'
                ? $item->job?->graphic_main_designer_id : $item->job?->structural_main_designer_id;
            $individual = $item->assignment_source === 'individual' && $item->assigned_to
                ? User::find($item->assigned_to) : null;
            $keepIndividual = $individual && $individual->is_active && $individual->hasRole('Designer');
            $item->update([
                'status' => 'pending', 'not_required_by' => null, 'not_required_at' => null,
                'not_required_reason' => null,
                'assigned_to' => $keepIndividual ? $item->assigned_to : $mainId,
                'assignment_source' => $keepIndividual ? 'individual' : 'project_default',
                'updated_by' => $request->user()->id,
            ]);
            DesignUpdate::create(['design_item_id' => $item->id, 'note' => 'Restored as required Design work.', 'created_by' => $request->user()->id]);
            return $item;
        });

        return response()->json(['message' => 'Design task restored.', 'data' => new DesignItemResource($item->fresh(['job', 'assignedUser:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS]))]);
    }

    public function useProjectDefault(Request $request, DesignItem $item): JsonResponse
    {
        $this->authorizeScopeDecision($request, $item);
        $item = DB::transaction(function () use ($item, $request) {
            $item = DesignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (! $item->job?->project_enquiry_id || $item->generated_scope_key === null) {
                throw ValidationException::withMessages(['item' => ['Only primary project element tasks can use the project designer.']]);
            }
            if (in_array($item->status, ['done', 'cancelled', 'print_ready', 'production_ready', 'handed_off', 'not_required'], true)) {
                throw ValidationException::withMessages(['status' => ['Only unfinished required work can return to the project designer.']]);
            }
            if ($item->isOutsourced()) {
                throw ValidationException::withMessages(['item' => ['Clear outsourcing before using the project designer.']]);
            }
            $mainId = $item->stream === 'graphic'
                ? $item->job->graphic_main_designer_id : $item->job->structural_main_designer_id;
            if ($item->assignment_source === 'project_default' && $item->assigned_to == $mainId) return $item;
            if ($item->assigned_to != $mainId && $item->workSessions()->whereNull('ended_at')->exists()) {
                $this->workTracking->closeForWorkflow($item, 'paused', 'switched_task');
            }
            $item->update(['assigned_to' => $mainId, 'assignment_source' => 'project_default', 'updated_by' => $request->user()->id]);
            DesignUpdate::create(['design_item_id' => $item->id, 'note' => 'Assignment returned to the project '.$item->stream.' designer.', 'created_by' => $request->user()->id]);
            return $item;
        });

        if ($item->assigned_to) $this->notifications->notifyItemAssigned($item);
        return response()->json(['message' => 'Task now uses the project designer.', 'data' => new DesignItemResource($item->fresh(['job', 'assignedUser:id,name', ...self::HISTORY_RELATIONS]))]);
    }

    private function authorizeScopeDecision(Request $request, DesignItem $item): void
    {
        $user = $request->user();
        $job = $item->job;
        abort_unless($user && (
            DesignAccess::userCanAccessLeadViews($user)
            || $item->assigned_to == $user->id
            || $job?->graphic_main_designer_id == $user->id
            || $job?->structural_main_designer_id == $user->id
        ), 403, 'You cannot change this task requirement.');
    }

    public function redesign(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $redesign = $this->redesigns->requestFromDesignItem($item, $data['reason']);
        $this->notifications->notifyItemAssigned($redesign);

        return response()->json([
            'message' => 'Redesign item created successfully',
            'data' => new DesignItemResource($redesign->loadMissing('assignedUser:id,name')),
        ], 201);
    }

    public function markPrintReady(DesignItem $item): JsonResponse
    {
        $this->readiness->ensurePrintReady($item);

        $item->update([
            'status' => 'print_ready',
            'print_ready_at' => now(),
            'updated_by' => auth()->id(),
        ]);
        $this->workTracking->closeForWorkflow($item, 'completed');

        $handoff = $this->handoffs->createPrintingHandoffOnce($item->fresh(['job.enquiry.client', 'type', 'printMaterial', 'documents']));
        $this->printingIntake->accept($handoff);
        $this->notifications->notifyItemReady($item->fresh(['job.enquiry.client', 'type']));

        return response()->json([
            'message' => 'Graphic Design marked print ready and queued in Printing',
            'data' => new DesignItemResource($item->fresh(['job.documents', 'assignedUser:id,name', 'type', 'printMaterial', 'documents', 'handoffs', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS])),
        ]);
    }

    public function markProductionReady(DesignItem $item): JsonResponse
    {
        $this->readiness->ensureProductionReady($item);

        $item->update([
            'status' => 'production_ready',
            'production_ready_at' => now(),
            'updated_by' => auth()->id(),
        ]);
        $this->workTracking->closeForWorkflow($item, 'completed');

        $this->notifications->notifyItemReady($item->fresh(['job.enquiry.client', 'type']));

        return response()->json([
            'message' => 'Structural Design marked production ready',
            'data' => new DesignItemResource($item->fresh(['job.documents', 'assignedUser:id,name', 'type', 'documents', 'bomItems.material.baseUom', 'handoffs', 'workSessions.user:id,name', 'workSessions.endedBy:id,name', ...self::HISTORY_RELATIONS])),
        ]);
    }

    public function beginWork(DesignItem $item): JsonResponse
    {
        return response()->json([
            'message' => 'Design work started',
            'data' => new DesignItemResource($this->workTracking->begin($item)),
        ]);
    }

    public function pauseWork(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(DesignPauseReason::VALUES)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Design work paused',
            'data' => new DesignItemResource($this->workTracking->pause($item, $data['reason'], $data['details'] ?? null)),
        ]);
    }
}
