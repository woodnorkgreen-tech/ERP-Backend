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
use App\Modules\Design\Services\DimensionConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignItemController extends Controller
{
    public function __construct(
        private readonly DimensionConversionService $dimensions,
        private readonly DesignItemReadinessService $readiness,
        private readonly DesignHandoffService $handoffs
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

        return response()->json([
            'message' => 'Design item created successfully',
            'data' => new DesignItemResource($item),
        ], 201);
    }

    public function update(StoreDesignItemRequest $request, DesignItem $item): JsonResponse
    {
        $data = $this->dimensions->normalize($request->validated());
        $data['updated_by'] = auth()->id();

        $item->update($data);
        $item->load(['job', 'type', 'printMaterial', 'documents', 'bomItems.material.baseUom', 'handoffs']);

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

    public function markPrintReady(DesignItem $item): JsonResponse
    {
        $this->readiness->ensurePrintReady($item);

        $item->update([
            'status' => 'print_ready',
            'print_ready_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        $this->handoffs->createPrintingHandoffOnce($item->fresh(['job.enquiry.client', 'type', 'printMaterial', 'documents']));

        return response()->json([
            'message' => 'Graphic Design marked print ready and synced to Printing',
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

        return response()->json([
            'message' => 'Structural Design marked production ready',
            'data' => new DesignItemResource($item->fresh(['job', 'type', 'documents', 'bomItems.material.baseUom', 'handoffs'])),
        ]);
    }
}
