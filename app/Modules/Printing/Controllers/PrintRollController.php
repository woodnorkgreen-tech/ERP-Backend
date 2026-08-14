<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Models\PrintRoll;
use App\Modules\Printing\Resources\PrintJobConsumptionResource;
use App\Modules\Printing\Resources\PrintRollResource;
use App\Modules\Printing\Services\PrintRollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintRollController extends Controller
{
    public function __construct(private readonly PrintRollService $rolls)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rolls = PrintRoll::query()
            ->when($request->filled('material_id'), fn ($q) => $q->where('material_id', $request->integer('material_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($inner) => $inner
                    ->where('roll_code', 'like', $term)
                    ->orWhere('display_label', 'like', $term)
                    ->orWhere('material_name_snapshot', 'like', $term));
            })
            ->orderBy('received_at')
            ->paginate((int) $request->get('per_page', 30));

        return response()->json($rolls->through(fn ($roll) => new PrintRollResource($roll)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_id' => ['required', 'integer', 'exists:library_materials,id'],
            'source_inventory_log_id' => ['nullable', 'integer', 'exists:inventory_logs,id'],
            'print_material_request_id' => ['nullable', 'integer', 'exists:print_material_requests,id'],
            'received_at' => ['nullable', 'date'],
            'received_length_m' => ['required', 'numeric', 'min:0.001'],
            'roll_width_m' => ['required', 'numeric', 'min:0.001'],
            'status' => ['nullable', 'in:active,reserved,depleted,damaged,returned,reconciled,archived'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json(['data' => new PrintRollResource($this->rolls->createRoll($data))], 201);
    }

    public function show(PrintRoll $roll): JsonResponse
    {
        return response()->json([
            'data' => new PrintRollResource($roll),
            'timeline' => [
                'job_consumptions' => PrintJobConsumptionResource::collection($roll->jobConsumptions()
                    ->with('job')
                    ->whereHas('job', fn ($query) => $query->where('status', 'completed'))
                    ->latest()
                    ->get()),
                'manual_consumptions' => $roll->manualConsumptions()->latest()->get(),
            ],
        ]);
    }

    public function update(Request $request, PrintRoll $roll): JsonResponse
    {
        $data = $request->validate([
            'roll_width_m' => ['required', 'numeric', 'min:0.001'],
            'status' => ['nullable', 'in:active,reserved,depleted,damaged,returned,reconciled,archived'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $roll->update($data);

        return response()->json(['data' => new PrintRollResource($roll->fresh())]);
    }

    public function adjust(Request $request, PrintRoll $roll): JsonResponse
    {
        $data = $request->validate([
            'remaining_length_m' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => new PrintRollResource($this->rolls->adjust($roll, (float) $data['remaining_length_m'], $data['reason']))]);
    }

    public function destroy(PrintRoll $roll): JsonResponse
    {
        if ($roll->jobConsumptions()->exists() || $roll->manualConsumptions()->exists()) {
            return response()->json([
                'message' => 'This roll already has consumption records and cannot be deleted.',
            ], 422);
        }

        $roll->delete();

        return response()->json(null, 204);
    }
}
