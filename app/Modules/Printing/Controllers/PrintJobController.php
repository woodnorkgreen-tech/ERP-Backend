<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Design\Resources\DesignItemResource;
use App\Modules\Design\Services\DesignRedesignService;
use App\Modules\HR\Models\Department;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Resources\PrintJobConsumptionResource;
use App\Modules\Printing\Resources\PrintJobResource;
use App\Modules\Printing\Services\PrintJobService;
use App\Modules\Printing\Services\PrintMaterialUsageService;
use App\Modules\Printing\Services\PrintWorkTrackingService;
use App\Modules\Printing\Services\ClientArtworkChangeService;
use App\Modules\Printing\Support\PrintPauseReason;
use App\Modules\Printing\Support\PrintVarianceReason;
use App\Support\ProjectSetupSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PrintJobController extends Controller
{
    private const PILOT_STATUSES = ['queued', 'printing', 'reprint_required', 'completed', 'cancelled'];

    public function __construct(
        private readonly PrintJobService $jobs,
        private readonly PrintMaterialUsageService $usage,
        private readonly DesignRedesignService $redesigns,
        private readonly PrintWorkTrackingService $workTracking,
        private readonly ClientArtworkChangeService $artworkChanges
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $myActiveCount = PrintJob::query()
            ->where('operator_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled', 'reprint_required'])
            ->count();
        $myWorkApplied = $request->boolean('mine') && $myActiveCount > 0;

        $jobs = PrintJob::query()
            ->select('print_jobs.*')
            ->selectSub(ProjectSetupSchedule::dateQuery('print_jobs.project_enquiry_id', 'print_jobs.project_id'), 'project_setup_date')
            ->with([
                'consumptions' => fn ($query) => $query->latest(), 'consumptions.roll', 'operator', 'machine',
                'workSessions.user:id,name', 'workSessions.endedBy:id,name',
                'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name',
            ])
            ->when($myWorkApplied, fn ($q) => $q->where('operator_id', auth()->id()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('tab'), fn ($q) => $this->applyTab($q, (string) $request->get('tab')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', $request->string('order_type')))
            ->when($request->boolean('reprints_only'), fn ($q) => $q->where('order_type', 'reprint'))
            ->when($request->filled('project_enquiry_id'), fn ($q) => $q->where('project_enquiry_id', $request->integer('project_enquiry_id')))
            ->when(!$myWorkApplied && $request->filled('operator_id'), fn ($q) => $q->where('operator_id', $request->integer('operator_id')))
            ->when($request->filled('machine_asset_id'), fn ($q) => $q->where('machine_asset_id', $request->integer('machine_asset_id')))
            ->when($request->filled('material_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('material_id', $request->integer('material_id'))))
            ->when($request->filled('print_roll_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('print_roll_id', $request->integer('print_roll_id'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) >= ?', [$request->date('date_from')->format('Y-m-d')]))
            ->when($request->filled('date_to'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) <= ?', [$request->date('date_to')->format('Y-m-d')]))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('job_number', 'like', $term)
                    ->orWhere('project_name', 'like', $term)
                    ->orWhere('client_name', 'like', $term));
            })
            ->orderByRaw("CASE WHEN status IN ('completed', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw('project_setup_date IS NULL')
            ->orderBy('project_setup_date')
            ->orderByRaw("FIELD(status, 'queued', 'printing', 'reprint_required', 'completed', 'cancelled', 'preflight', 'ready_to_print', 'printed', 'qc_failed')")
            ->latest()
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 30));

        $response = $jobs->toArray();
        $response['data'] = PrintJobResource::collection($jobs->getCollection())->resolve();
        $response['my_work_active_count'] = $myActiveCount;
        $response['my_work_applied'] = $myWorkApplied;
        $response['stop_alerts'] = PrintJobResource::collection(
            PrintJob::query()
                ->with(['operator', 'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name'])
                ->whereNotNull('stop_required_at')
                ->whereNull('stop_acknowledged_at')
                ->when(auth()->id(), fn ($q) => $q->where(fn ($alerts) => $alerts->where('operator_id', auth()->id())->orWhereNull('operator_id')))
                ->latest('stop_required_at')
                ->get()
        )->resolve();

        return response()->json($response);
    }

    public function show(PrintJob $job): JsonResponse
    {
        return response()->json(['data' => new PrintJobResource($job->load(['consumptions.roll', 'operator', 'machine', 'events', 'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name']))]);
    }

    public function bundles(Request $request): JsonResponse
    {
        $myActiveCount = PrintJob::query()
            ->where('operator_id', auth()->id())
            ->whereNotIn('status', ['completed', 'cancelled', 'reprint_required'])
            ->count();
        $myWorkApplied = $request->boolean('mine') && $myActiveCount > 0;

        $query = PrintJob::query()
            ->when($myWorkApplied, fn ($q) => $q->where('operator_id', auth()->id()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('tab'), fn ($q) => $this->applyTab($q, (string) $request->get('tab')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', $request->string('order_type')))
            ->when($request->boolean('reprints_only'), fn ($q) => $q->where('order_type', 'reprint'))
            ->when(!$myWorkApplied && $request->filled('operator_id'), fn ($q) => $q->where('operator_id', $request->integer('operator_id')))
            ->when($request->filled('machine_asset_id'), fn ($q) => $q->where('machine_asset_id', $request->integer('machine_asset_id')))
            ->when($request->filled('material_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('material_id', $request->integer('material_id'))))
            ->when($request->filled('print_roll_id'), fn ($q) => $q->whereHas('consumptions', fn ($inner) => $inner->where('print_roll_id', $request->integer('print_roll_id'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) >= ?', [$request->date('date_from')->format('Y-m-d')]))
            ->when($request->filled('date_to'), fn ($q) => $q->whereRaw('DATE(COALESCE(completed_at, scheduled_at, created_at)) <= ?', [$request->date('date_to')->format('Y-m-d')]))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('job_number', 'like', $term)
                    ->orWhere('project_name', 'like', $term)
                    ->orWhere('client_name', 'like', $term));
            });

        $candidates = $query->select('id', 'project_id', 'project_enquiry_id', 'design_job_id')->get();
        $candidateGroups = $candidates->groupBy(fn (PrintJob $job) => $this->printBundleKey($job));
        $keys = $candidateGroups->keys()->values();
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 30)));
        $selectedKeys = $keys->slice(($page - 1) * $perPage, $perPage)->values();
        $descriptors = $selectedKeys->map(fn (string $key) => $this->printBundleDescriptor($key));

        $jobs = $descriptors->isEmpty() ? collect() : PrintJob::query()
            ->select('print_jobs.*')
            ->selectSub(ProjectSetupSchedule::dateQuery('print_jobs.project_enquiry_id', 'print_jobs.project_id'), 'project_setup_date')
            ->with([
                'consumptions' => fn ($usage) => $usage->latest(), 'consumptions.roll', 'operator', 'machine',
                'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name',
                'workSessions.user:id,name', 'workSessions.endedBy:id,name',
            ])
            ->where(function ($match) use ($descriptors) {
                foreach ($descriptors as $descriptor) {
                    $match->orWhere(function ($group) use ($descriptor) {
                        if ($descriptor['type'] === 'project') {
                            $group->where('project_id', $descriptor['id']);
                        } elseif ($descriptor['type'] === 'enquiry') {
                            $group->whereNull('project_id')->where('project_enquiry_id', $descriptor['id']);
                        } elseif ($descriptor['type'] === 'design') {
                            $group->whereNull('project_id')->whereNull('project_enquiry_id')->where('design_job_id', $descriptor['id']);
                        } else {
                            $group->whereKey($descriptor['id']);
                        }
                    });
                }
            })
            ->orderByRaw("FIELD(status, 'printing', 'queued', 'reprint_required', 'completed', 'cancelled')")
            ->latest()
            ->get();
        $allGroups = $jobs->groupBy(fn (PrintJob $job) => $this->printBundleKey($job));

        $data = $selectedKeys->map(function (string $key) use ($allGroups, $candidateGroups) {
            $jobs = $allGroups->get($key, collect())->values();
            $first = $jobs->first();
            $elements = $jobs->groupBy(fn (PrintJob $job) => $job->design_item_id ? 'design:' . $job->design_item_id : 'print:' . $job->id)
                ->map(fn ($runs) => $runs->sortByDesc('id')->first());
            $total = $elements->count();
            $completed = $elements->where('status', 'completed')->count();
            $matchingIds = $candidateGroups->get($key, collect())->pluck('id')->values();

            return [
                'key' => $key,
                'project_id' => $first?->project_id,
                'project_enquiry_id' => $first?->project_enquiry_id,
                'job_number' => $first?->job_number,
                'project_name' => $first?->project_name ?? $first?->title ?? 'Standalone printing work',
                'client_name' => $first?->client_name,
                'project_setup_date' => $first?->project_setup_date,
                'total_elements' => $total,
                'matching_jobs' => $matchingIds->count(),
                'matching_job_ids' => $matchingIds,
                'print_runs' => $jobs->count(),
                'completed_elements' => $completed,
                'working_elements' => $elements->where('status', 'printing')->count(),
                'waiting_elements' => $elements->whereIn('status', ['queued', 'reprint_required'])->count(),
                'progress_percent' => $total ? (int) round(($completed / $total) * 100) : 0,
                'needs_attention' => $jobs->contains(fn (PrintJob $job) => ($job->stop_required_at && !$job->stop_acknowledged_at) || $job->status === 'reprint_required'),
                'jobs' => PrintJobResource::collection($jobs)->resolve(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($keys->count() / $perPage)),
            'total' => $keys->count(),
            'per_page' => $perPage,
            'my_work_active_count' => $myActiveCount,
            'my_work_applied' => $myWorkApplied,
            'stop_alerts' => $this->activeStopAlerts(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['required', 'date'],
            'priority' => ['required', 'in:normal,urgent'],
            'request_source' => ['required', 'in:site_request,urgent_request,internal_work,client_request,other'],
            'requested_by_name' => ['required', 'string', 'max:255'],
            'bypass_reason' => ['required', 'string', 'max:2000'],
            'final_artwork_url' => ['nullable', 'url', 'max:2000'],
            'design_height_m' => ['nullable', 'numeric', 'min:0'],
            'design_length_m' => ['nullable', 'numeric', 'min:0'],
            'print_width_m' => ['nullable', 'numeric', 'min:0'],
            'running_length_m' => ['nullable', 'numeric', 'min:0'],
            'artwork_quantity' => ['nullable', 'numeric', 'min:0.001'],
            'remarks' => ['nullable', 'string', 'max:3000'],
        ]);

        $job = DB::transaction(function () use ($data) {
            $project = ! empty($data['project_id'])
                ? Project::with('enquiry.client')->findOrFail($data['project_id'])
                : null;
            $enquiry = $project?->enquiry;

            $job = PrintJob::create([
                ...$data,
                'origin' => 'manual',
                'project_enquiry_id' => $project?->enquiry_id,
                'client_id' => $enquiry?->client_id,
                'job_number' => $enquiry?->job_number ?: null,
                'project_name' => $enquiry?->title,
                'client_name' => $enquiry?->client?->full_name,
                'order_type' => 'original',
                'status' => 'queued',
                'artwork_version' => 1,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            if (! $job->job_number) {
                $job->update(['job_number' => sprintf('PRN-MAN-%s-%04d', now()->format('ymd'), $job->id)]);
            }

            $job->events()->create([
                'event_type' => 'manual_job_created',
                'to_status' => 'queued',
                'reason' => $data['bypass_reason'],
                'payload' => [
                    'request_source' => $data['request_source'],
                    'requested_by_name' => $data['requested_by_name'],
                    'project_id' => $project?->id,
                ],
                'created_by' => auth()->id(),
            ]);

            return $job->fresh(['consumptions.roll', 'operator', 'machine']);
        });

        return response()->json(['data' => new PrintJobResource($job)], 201);
    }

    public function update(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate($this->updateRules());

        return response()->json(['data' => new PrintJobResource($this->jobs->update($job, $data))]);
    }

    public function status(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', self::PILOT_STATUSES)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => new PrintJobResource($this->jobs->transition($job, $data['status'], $data['reason'] ?? null))]);
    }

    public function complete(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => new PrintJobResource($this->jobs->transition($job, 'completed', $data['reason'] ?? null))]);
    }

    public function beginWork(PrintJob $job): JsonResponse
    {
        return response()->json([
            'message' => 'Printing work started',
            'data' => new PrintJobResource($this->workTracking->begin($job)),
        ]);
    }

    public function pauseWork(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(PrintPauseReason::VALUES)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Printing work paused',
            'data' => new PrintJobResource($this->workTracking->pause($job, $data['reason'], $data['details'] ?? null)),
        ]);
    }

    public function acknowledgeStop(PrintJob $job): JsonResponse
    {
        return response()->json([
            'message' => 'Printing stopped. The outdated artwork has been removed from active work.',
            'data' => new PrintJobResource($this->artworkChanges->acknowledgeStop($job)),
        ]);
    }

    public function reprint(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => new PrintJobResource($this->jobs->reprint($job, $data['reason']))], 201);
    }

    public function redesign(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json([
            'message' => 'Redesign item created in Design',
            'data' => new DesignItemResource($this->redesigns->requestFromPrintJob($job, $data['reason'])),
        ], 201);
    }

    public function correction(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'status' => ['nullable', 'in:' . implode(',', self::PILOT_STATUSES)],
        ]);

        $job->events()->create([
            'event_type' => 'correction_requested',
            'reason' => $data['reason'],
            'payload' => $data,
            'created_by' => auth()->id(),
        ]);

        if (!empty($data['status'])) {
            $job->update(['status' => $data['status'], 'updated_by' => auth()->id()]);
        }

        return response()->json(['data' => new PrintJobResource($job->fresh(['consumptions.roll', 'operator', 'machine']))]);
    }

    public function consumptions(PrintJob $job): JsonResponse
    {
        return response()->json(['data' => PrintJobConsumptionResource::collection($job->consumptions()->with('roll')->get())]);
    }

    public function saveConsumption(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate([
            'print_roll_id' => ['required', 'integer', 'exists:print_rolls,id'],
            'artwork_width_m' => ['nullable', 'numeric', 'min:0'],
            'artwork_height_m' => ['nullable', 'numeric', 'min:0'],
            'artwork_count' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'tile_count' => ['nullable', 'integer', 'min:1'],
            'bleed_preset' => ['nullable', 'string', 'max:100'],
            'bleed_left_m' => ['nullable', 'numeric', 'min:0'],
            'bleed_right_m' => ['nullable', 'numeric', 'min:0'],
            'bleed_top_m' => ['nullable', 'numeric', 'min:0'],
            'bleed_bottom_m' => ['nullable', 'numeric', 'min:0'],
            'spacing_m' => ['nullable', 'numeric', 'min:0'],
            'setup_allowance_m' => ['nullable', 'numeric', 'min:0'],
            'actual_running_m' => ['nullable', 'numeric', 'min:0'],
            'variance_reason' => ['nullable', 'string', 'max:255'],
            'variance_reason_code' => ['nullable', Rule::in(PrintVarianceReason::VALUES)],
        ]);

        return response()->json(['data' => new PrintJobConsumptionResource($this->usage->saveJobConsumption($job, $data))], 201);
    }

    private function applyTab($query, string $tab)
    {
        return match ($tab) {
            'queue' => $query->where('status', 'queued'),
            'in_progress' => $query->where('status', 'printing'),
            'needs_attention' => $query->where('status', 'reprint_required'),
            'completed' => $query->where('status', 'completed'),
            default => $query,
        };
    }

    private function printBundleKey(PrintJob $job): string
    {
        if ($job->project_id) return 'project:' . $job->project_id;
        if ($job->project_enquiry_id) return 'enquiry:' . $job->project_enquiry_id;
        if ($job->design_job_id) return 'design:' . $job->design_job_id;

        return 'print:' . $job->id;
    }

    private function printBundleDescriptor(string $key): array
    {
        [$type, $id] = explode(':', $key, 2);

        return ['type' => $type, 'id' => (int) $id];
    }

    private function activeStopAlerts(): array
    {
        return PrintJobResource::collection(
            PrintJob::query()
                ->with(['operator', 'stopRequestedBy:id,name', 'stopAcknowledgedBy:id,name', 'workSessions.user:id,name', 'workSessions.endedBy:id,name'])
                ->whereNotNull('stop_required_at')
                ->whereNull('stop_acknowledged_at')
                ->when(auth()->id(), fn ($q) => $q->where(fn ($alerts) => $alerts->where('operator_id', auth()->id())->orWhereNull('operator_id')))
                ->latest('stop_required_at')
                ->get()
        )->resolve();
    }

    private function updateRules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'operator_id' => ['nullable', 'integer', $this->designOperatorRule()],
            'machine_asset_id' => ['nullable', 'integer', $this->printingMachineRule()],
            'remarks' => ['nullable', 'string', 'max:3000'],
            'status' => ['sometimes', 'in:' . implode(',', self::PILOT_STATUSES)],
        ];
    }

    private function designOperatorRule()
    {
        $departmentIds = Department::query()
            ->where('name', 'like', '%design%')
            ->orWhere('name', 'like', '%creative%')
            ->pluck('id');

        return Rule::exists('users', 'id')
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('department_id', $departmentIds));
    }

    private function printingMachineRule()
    {
        $categoryIds = AssetCategory::query()
            ->where('name', 'like', '%print%')
            ->pluck('id');
        $departmentIds = Department::query()
            ->where('name', 'like', '%print%')
            ->pluck('id');

        return Rule::exists('assets', 'id')
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', '%print%')
                    ->orWhere('category', 'like', '%print%')
                    ->orWhere('subcategory', 'like', '%print%')
                    ->orWhereIn('category_id', $categoryIds)
                    ->orWhereIn('department_id', $departmentIds)));
    }
}
