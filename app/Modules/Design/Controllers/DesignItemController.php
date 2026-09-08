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
use App\Modules\Printing\Services\PrintIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignItemController extends Controller
{
    public function __construct(
        private readonly DimensionConversionService $dimensions,
        private readonly DesignItemReadinessService $readiness,
        private readonly DesignHandoffService $handoffs,
        private readonly PrintIntakeService $printingIntake,
        private readonly DesignNotificationService $notifications,
        private readonly DesignRedesignService $redesigns
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
        $query = DesignItem::where('stream', $stream)
            ->with(['job.enquiry.deliverables', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs']);

        $status = $request->input('status', $request->route('status'));
        if ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('design_job_id')) {
            $query->where('design_job_id', $request->design_job_id);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where('title', 'like', "%{$search}%");
        }

        $items = $query->latest()->paginate($request->get('per_page', 25));

        return response()->json([
            'data' => DesignItemResource::collection($items)->resolve(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
            'per_page' => $items->perPage(),
        ]);
    }

    public function store(StoreDesignItemRequest $request, DesignJob $job, string $stream): JsonResponse
    {
        $data = $this->dimensions->normalize($request->validated());
        $data['stream'] = $stream;
        $data['design_job_id'] = $job->id;
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $item = DesignItem::create($data)->load(['job', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs']);

        $this->notifications->notifyItemAssigned($item);

        return response()->json([
            'message' => 'Design item created successfully',
            'data' => new DesignItemResource($item),
        ], 201);
    }

    public function update(StoreDesignItemRequest $request, DesignItem $item): JsonResponse
    {
        $previousAssignedTo = $item->assigned_to;
        $previousStatus = $item->status;

        $data = $this->dimensions->normalize($request->validated());
        $data['updated_by'] = auth()->id();

        if (array_key_exists('status', $data) && $data['status'] !== 'print_ready' && $previousStatus === 'print_ready') {
            $data['print_ready_at'] = null;
        }

        $item->update($data);
        $item->load(['job', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs', 'assignedUser']);

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

        return response()->json([
            'message' => 'Design item updated successfully',
            'data' => new DesignItemResource($item),
        ]);
    }

    public function destroy(DesignItem $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Design item deleted successfully']);
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
            'data' => new DesignItemResource($redesign),
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

        $handoff = $this->handoffs->createPrintingHandoffOnce($item->fresh(['job.enquiry.client', 'type', 'printMaterial', 'documents']));
        $this->printingIntake->accept($handoff);
        $this->notifications->notifyItemReady($item->fresh(['job.enquiry.client', 'type']));

        return response()->json([
            'message' => 'Graphic Design marked print ready and queued in Printing',
            'data' => new DesignItemResource($item->fresh(['job', 'type', 'printMaterial', 'documents', 'handoffs'])),
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

        $this->notifications->notifyItemReady($item->fresh(['job.enquiry.client', 'type']));

        return response()->json([
            'message' => 'Structural Design marked production ready',
            'data' => new DesignItemResource($item->fresh(['job', 'type', 'documents', 'bomItems.material.baseUom', 'handoffs'])),
        ]);
    }
}
