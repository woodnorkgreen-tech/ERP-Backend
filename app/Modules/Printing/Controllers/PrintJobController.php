<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\HR\Models\Department;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Resources\PrintJobConsumptionResource;
use App\Modules\Printing\Resources\PrintJobResource;
use App\Modules\Printing\Services\PrintJobService;
use App\Modules\Printing\Services\PrintMaterialUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrintJobController extends Controller
{
    private const PILOT_STATUSES = ['queued', 'printing', 'reprint_required', 'completed', 'cancelled'];

    public function __construct(
        private readonly PrintJobService $jobs,
        private readonly PrintMaterialUsageService $usage
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $jobs = PrintJob::query()
            ->with(['consumptions' => fn ($query) => $query->latest(), 'consumptions.roll', 'operator', 'machine'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('tab'), fn ($q) => $this->applyTab($q, (string) $request->get('tab')))
            ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', $request->string('order_type')))
            ->when($request->boolean('reprints_only'), fn ($q) => $q->where('order_type', 'reprint'))
            ->when($request->filled('project_enquiry_id'), fn ($q) => $q->where('project_enquiry_id', $request->integer('project_enquiry_id')))
            ->when($request->filled('operator_id'), fn ($q) => $q->where('operator_id', $request->integer('operator_id')))
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
            ->orderByRaw("FIELD(status, 'queued', 'printing', 'reprint_required', 'completed', 'cancelled', 'preflight', 'ready_to_print', 'printed', 'qc_failed')")
            ->latest()
            ->paginate((int) $request->get('per_page', 30));

        return response()->json($jobs->through(fn ($job) => new PrintJobResource($job)));
    }

    public function show(PrintJob $job): JsonResponse
    {
        return response()->json(['data' => new PrintJobResource($job->load(['consumptions.roll', 'operator', 'machine', 'events']))]);
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

    public function reprint(Request $request, PrintJob $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => new PrintJobResource($this->jobs->reprint($job, $data['reason']))], 201);
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
