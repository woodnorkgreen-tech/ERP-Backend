<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Models\PrintMaterialRequest;
use App\Modules\Printing\Resources\PrintMaterialRequestResource;
use App\Modules\Printing\Resources\PrintRollResource;
use App\Modules\Printing\Services\PrintRollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintMaterialRequestController extends Controller
{
    public function __construct(private readonly PrintRollService $rolls)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $requests = PrintMaterialRequest::query()
            ->with('material')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('material_id'), fn ($q) => $q->where('material_id', $request->integer('material_id')))
            ->latest()
            ->paginate((int) $request->get('per_page', 20));

        return response()->json($requests->through(fn ($item) => new PrintMaterialRequestResource($item)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:library_materials,id'],
            'requested_quantity_m' => ['required', 'numeric', 'min:0.001'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_enquiry_id' => ['nullable', 'integer', 'exists:project_enquiries,id'],
            'print_job_id' => ['nullable', 'integer', 'exists:print_jobs,id'],
            'urgency' => ['nullable', 'in:normal,urgent'],
            'reason' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,requested,approved,issued,received,rejected,cancelled'],
        ]);

        $materialRequest = PrintMaterialRequest::create($data + [
            'status' => $data['status'] ?? 'requested',
            'requested_by' => auth()->id(),
        ]);

        return response()->json(['data' => new PrintMaterialRequestResource($materialRequest->load('material'))], 201);
    }

    public function receive(Request $request, PrintMaterialRequest $materialRequest): JsonResponse
    {
        $data = $request->validate([
            'stores_inventory_log_id' => ['nullable', 'integer', 'exists:inventory_logs,id'],
            'rolls' => ['required', 'array', 'min:1'],
            'rolls.*.received_length_m' => ['required', 'numeric', 'min:0.001'],
            'rolls.*.roll_width_m' => ['required', 'numeric', 'min:0.001'],
            'rolls.*.received_at' => ['nullable', 'date'],
            'rolls.*.location' => ['nullable', 'string', 'max:255'],
            'rolls.*.notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $created = collect($data['rolls'])->map(function (array $roll) use ($data, $materialRequest) {
            return $this->rolls->createRoll($roll + [
                'material_id' => $materialRequest->material_id,
                'print_material_request_id' => $materialRequest->id,
                'source_inventory_log_id' => $data['stores_inventory_log_id'] ?? $materialRequest->stores_inventory_log_id,
            ]);
        });

        $materialRequest->update([
            'status' => 'received',
            'stores_inventory_log_id' => $data['stores_inventory_log_id'] ?? $materialRequest->stores_inventory_log_id,
            'received_by' => auth()->id(),
        ]);

        return response()->json([
            'data' => new PrintMaterialRequestResource($materialRequest->fresh('material')),
            'rolls' => PrintRollResource::collection($created),
        ]);
    }

    public function destroy(PrintMaterialRequest $materialRequest): JsonResponse
    {
        if ($materialRequest->rolls()->exists()) {
            return response()->json([
                'message' => 'This request has received rolls and cannot be deleted. Delete the unused rolls first.',
            ], 422);
        }

        $materialRequest->delete();

        return response()->json(null, 204);
    }
}
