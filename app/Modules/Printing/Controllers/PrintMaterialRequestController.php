<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Printing\Models\PrintMaterialRequest;
use App\Modules\Printing\Resources\PrintMaterialRequestResource;
use App\Modules\Printing\Services\PrintMaterialRequestFulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PrintMaterialRequestController extends Controller
{
    public function __construct(private readonly PrintMaterialRequestFulfilmentService $fulfilment) {}

    public function index(Request $request): JsonResponse
    {
        $requests = PrintMaterialRequest::query()
            ->with([
                'material.stock', 'material.baseUom', 'material.issueUom', 'material.uomConversions',
                'printJob', 'requester', 'fulfilments.issuer',
            ])
            ->withSum('fulfilments', 'issued_quantity_m')
            ->when($request->boolean('stores_queue'), fn ($q) => $q->whereIn('status', [
                'awaiting_stores', 'awaiting_purchase', 'partially_fulfilled', 'requested',
            ]))
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
        ]);

        if (! empty($data['print_job_id'])) {
            $job = \App\Modules\Printing\Models\PrintJob::findOrFail($data['print_job_id']);
            $data['project_id'] = $job->project_id;
            $data['project_enquiry_id'] = $job->project_enquiry_id;
        }

        $materialRequest = PrintMaterialRequest::create($data + [
            'status' => 'awaiting_stores',
            'requested_by' => auth()->id(),
        ]);

        return response()->json(['data' => new PrintMaterialRequestResource($materialRequest->load('material'))], 201);
    }

    public function receive(Request $request, PrintMaterialRequest $materialRequest): JsonResponse
    {
        throw ValidationException::withMessages([
            'status' => ['Materials are received automatically when Stores issues them.'],
        ]);
    }

    public function issue(Request $request, PrintMaterialRequest $materialRequest): JsonResponse
    {
        $this->ensureStoresUser($request);
        $data = $request->validate([
            'rolls' => ['required', 'array', 'min:1'],
            'rolls.*.received_length_m' => ['required', 'numeric', 'min:0.001'],
            'rolls.*.roll_width_m' => ['required', 'numeric', 'min:0.001'],
            'rolls.*.notes' => ['nullable', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $updated = $this->fulfilment->issue($materialRequest, $data['rolls'], $data['notes'] ?? null);

        return response()->json(['data' => new PrintMaterialRequestResource($this->loadForResponse($updated))]);
    }

    public function awaitingPurchase(Request $request, PrintMaterialRequest $materialRequest): JsonResponse
    {
        $this->ensureStoresUser($request);
        $updated = $this->fulfilment->markAwaitingPurchase($materialRequest);

        return response()->json(['data' => new PrintMaterialRequestResource($this->loadForResponse($updated))]);
    }

    public function destroy(PrintMaterialRequest $materialRequest): JsonResponse
    {
        if ($materialRequest->rolls()->exists() || $materialRequest->fulfilments()->exists()) {
            return response()->json([
                'message' => 'This request has received rolls and cannot be deleted. Delete the unused rolls first.',
            ], 422);
        }

        $materialRequest->delete();

        return response()->json(null, 204);
    }

    private function ensureStoresUser(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin']), 403);
    }

    private function loadForResponse(PrintMaterialRequest $request): PrintMaterialRequest
    {
        return $request->load([
            'material.stock', 'material.baseUom', 'material.issueUom', 'material.uomConversions',
            'printJob', 'requester', 'fulfilments.issuer',
        ])->loadSum('fulfilments', 'issued_quantity_m');
    }
}
