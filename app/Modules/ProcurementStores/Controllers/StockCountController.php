<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StockCount;
use App\Modules\ProcurementStores\Models\StockCountItem;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    private function storesUser(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin']), 403, 'Only Stores team members can manage stock counts.');
    }

    private function reviewUser(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['Manager', 'Super Admin']), 403, 'A Manager must review stock-count variances.');
    }

    public function index(): JsonResponse
    {
        $this->storesUser();
        $rows = StockCount::with(['creator:id,name', 'reviewer:id,name'])->withCount('items')->latest()->limit(50)->get();
        return response()->json(['data' => $rows]);
    }

    public function show(StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        return response()->json(['data' => $stockCount->load(['creator:id,name', 'submitter:id,name', 'reviewer:id,name', 'items.material.baseUom:id,code,name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->storesUser();
        $validated = $request->validate(['warehouse_code' => 'nullable|string|max:40', 'counted_on' => 'required|date|before_or_equal:today', 'notes' => 'nullable|string|max:1000']);

        $count = DB::transaction(function () use ($validated) {
            $count = StockCount::create([
                'count_number' => 'SC-'.now()->format('Ymd-His').'-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'warehouse_code' => $validated['warehouse_code'] ?? 'MAIN', 'status' => 'draft',
                'counted_on' => $validated['counted_on'], 'notes' => $validated['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            Stock::with('material')->where('warehouse_code', $count->warehouse_code)->get()
                ->filter(fn (Stock $stock) => $stock->material
                    && ! $stock->material->isBoardTrackable()
                    && ! $stock->material->is_serialized
                    && ! $stock->material->is_batch_controlled
                    && ! $stock->material->is_expiry_controlled)
                ->each(fn (Stock $stock) => $count->items()->create(['material_id' => $stock->material_id, 'system_quantity' => $stock->quantity_on_hand]));
            return $count;
        });
        return $this->show($count);
    }

    public function update(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        if ($stockCount->status !== 'draft') return response()->json(['message' => 'Only a draft count can be edited.'], 422);
        $validated = $request->validate([
            'items' => 'required|array|min:1', 'items.*.id' => 'required|integer',
            'items.*.counted_quantity' => 'required|numeric|min:0', 'items.*.variance_reason' => 'nullable|string|max:500',
        ]);
        DB::transaction(function () use ($stockCount, $validated) {
            foreach ($validated['items'] as $input) {
                $item = $stockCount->items()->whereKey($input['id'])->lockForUpdate()->firstOrFail();
                $variance = (float) $input['counted_quantity'] - (float) $item->system_quantity;
                $item->update(['counted_quantity' => $input['counted_quantity'], 'variance_quantity' => $variance, 'variance_reason' => $input['variance_reason'] ?? null]);
            }
        });
        return $this->show($stockCount->fresh());
    }

    public function submit(StockCount $stockCount): JsonResponse
    {
        $this->storesUser();
        if ($stockCount->status !== 'draft') return response()->json(['message' => 'Only a draft count can be submitted.'], 422);
        if ($stockCount->items()->whereNull('counted_quantity')->exists()) throw ValidationException::withMessages(['items' => 'Enter the physical quantity for every material before submitting.']);
        if ($stockCount->items()->where('variance_quantity', '!=', 0)->where(fn ($q) => $q->whereNull('variance_reason')->orWhere('variance_reason', ''))->exists()) throw ValidationException::withMessages(['items' => 'Explain every difference before submitting the count.']);
        $stockCount->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now()]);
        return response()->json(['message' => 'Stock count submitted for manager review.', 'data' => $stockCount]);
    }

    public function approve(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->reviewUser();
        if ($stockCount->status !== 'submitted') return response()->json(['message' => 'Only a submitted count can be approved.'], 422);
        if ((int) $stockCount->created_by === (int) auth()->id()) return response()->json(['message' => 'The person who created the count cannot approve its adjustments.'], 422);
        $validated = $request->validate(['review_notes' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($stockCount, $validated) {
            $locked = StockCount::with('items')->lockForUpdate()->findOrFail($stockCount->id);
            foreach ($locked->items as $item) {
                $variance = (float) $item->variance_quantity;
                if (abs($variance) < 0.000001) continue;
                $stock = Stock::where('material_id', $item->material_id)->lockForUpdate()->firstOrFail();
                if (abs((float) $stock->quantity_on_hand - (float) $item->system_quantity) > 0.000001) {
                    throw ValidationException::withMessages(['count' => 'Stock changed after counting. Start a new count so no later movement is overwritten.']);
                }
                $log = app(InventoryService::class)->adjustStock($item->material_id, $variance, 'adjustment', ['reference_no' => $locked->count_number, 'notes' => 'Approved physical count variance: '.$item->variance_reason, 'logged_at' => now()]);
                $item->update(['adjustment_log_id' => $log->id]);
            }
            $locked->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => $validated['review_notes'] ?? null]);
        });
        return response()->json(['message' => 'Count approved and stock variances posted once.']);
    }

    public function reject(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->reviewUser();
        if ($stockCount->status !== 'submitted') return response()->json(['message' => 'Only a submitted count can be rejected.'], 422);
        $validated = $request->validate(['review_notes' => 'required|string|min:5|max:1000']);
        $stockCount->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_notes' => $validated['review_notes']]);
        return response()->json(['message' => 'Count rejected. Start a new count after correcting the problem.']);
    }
}
