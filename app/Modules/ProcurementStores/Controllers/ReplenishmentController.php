<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Services\ReplenishmentPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Buying that starts from the numbers instead of from somebody noticing.
 *
 * The suggestion is arithmetic; the requisition is a decision. This deliberately
 * keeps them apart — `draft()` writes a DRAFT requisition and stops. Nothing is
 * submitted, nothing is approved, and the existing approval path is untouched,
 * so the buyer still owns every purchase. What changes is that they start from
 * a filled-in list of what the store is actually short of rather than a blank
 * form and a memory.
 */
class ReplenishmentController extends Controller
{
    /** The same audience the demand forecast answers to. */
    private const ROLES = ['Stores', 'Procurement', 'Manager', 'Super Admin'];

    private function permitted(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(self::ROLES);
    }

    public function index(ReplenishmentPlanner $planner): JsonResponse
    {
        if (! $this->permitted()) {
            return response()->json(['message' => 'You are not permitted to view replenishment suggestions.'], 403);
        }

        $suggestions = $planner->suggestions();

        return response()->json([
            'data' => $suggestions->all(),
            'summary' => [
                'materials' => $suggestions->count(),
                'job_shortfall' => $suggestions->where('reason', 'job_shortfall')->count(),
                'below_minimum' => $suggestions->where('reason', 'below_minimum')->count(),
                'indicative_value' => round($suggestions->sum(
                    fn (array $row) => $row['suggested_quantity'] * $row['unit_price']
                ), 2),
            ],
        ]);
    }

    /**
     * Turn chosen suggestions into one draft requisition.
     *
     * Quantities are taken from the request, not recomputed, because the buyer
     * is allowed to disagree with the arithmetic — rounding to a pack size,
     * ordering ahead of a known job, or trimming what the cash will not stretch
     * to. What the planner supplies is the starting number, and the draft
     * records what was actually asked for.
     */
    public function draft(Request $request, ReplenishmentPlanner $planner): JsonResponse
    {
        if (! $this->permitted()) {
            return response()->json(['message' => 'You are not permitted to raise replenishment requisitions.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer|exists:departments,id',
            'urgency' => 'nullable|in:normal,urgent',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|integer|exists:library_materials,id',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $requested = collect($request->input('items'))->keyBy(fn ($item) => (int) $item['material_id']);

        // Priced and coded from the planner where it has an opinion, so a draft
        // carries the same figures the suggestion screen showed. A material the
        // planner no longer lists — bought in the meantime, or its shortfall
        // closed by a receipt — is still allowed through: the buyer asked for
        // it, and refusing would turn a stale screen into a lost basket.
        $planned = $planner->suggestions()->keyBy('material_id');

        $materials = LibraryMaterial::with(['purchaseUom', 'baseUom'])
            ->whereIn('id', $requested->keys())
            ->get()
            ->keyBy('id');

        $requisition = DB::transaction(function () use ($requested, $planned, $materials, $request) {
            $requisition = Requisition::create([
                'requisition_number' => Requisition::generateRequisitionNumber(),
                'date' => now()->toDateString(),
                // Stock replenishment belongs to no single job. Material bought
                // for a job still routes through Stores and is costed when it
                // is issued, so attaching this to a project here would charge
                // that project for stock the whole workshop draws on.
                'requested_by_type' => 'office',
                'department_id' => (int) $request->input('department_id'),
                'urgency' => $request->input('urgency')
                    ?? ($planned->contains(fn ($row) => $row['reason'] === 'job_shortfall') ? 'urgent' : 'normal'),
                'status' => 'draft',
                'total_amount' => 0,
                'user_id' => auth()->id(),
            ]);

            $total = 0.0;

            foreach ($requested as $materialId => $item) {
                $material = $materials->get($materialId);
                $suggestion = $planned->get($materialId);
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) ($suggestion['unit_price']
                    ?? ((float) $material?->unit_cost > 0 ? $material->unit_cost : ($material?->default_unit_cost ?? 0)));

                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'material_id' => $materialId,
                    'expense_code_id' => $suggestion['expense_code_id'] ?? null,
                    'quantity' => $quantity,
                    'uom_id' => $material?->purchase_uom_id ?: $material?->base_uom_id,
                    'unit_price' => $unitPrice,
                    'total' => $quantity * $unitPrice,
                    'purpose' => $this->purposeFor($suggestion),
                ]);

                $total += $quantity * $unitPrice;
            }

            $requisition->update(['total_amount' => $total]);

            return $requisition;
        });

        return response()->json([
            'message' => 'Draft requisition raised from the replenishment plan. Review it, then submit it for approval.',
            'data' => [
                'id' => $requisition->id,
                'requisition_number' => $requisition->requisition_number,
                'status' => $requisition->status,
                'total_amount' => (float) $requisition->total_amount,
            ],
        ], 201);
    }

    /**
     * Why the line is on the requisition, in the words the approver needs.
     * `purpose` is required on every requisition item and is what an approver
     * reads first, so it says what the shortfall was rather than "replenishment".
     */
    private function purposeFor(?array $suggestion): string
    {
        if (! $suggestion) {
            return 'Stock replenishment';
        }

        return $suggestion['reason'] === 'job_shortfall'
            ? sprintf(
                'Approved jobs need %s; %s free and %s on order.',
                rtrim(rtrim(number_format($suggestion['pending_demand'], 2), '0'), '.'),
                rtrim(rtrim(number_format($suggestion['free_stock'], 2), '0'), '.'),
                rtrim(rtrim(number_format($suggestion['incoming'], 2), '0'), '.'),
            )
            : sprintf(
                'Below the %s minimum Stores holds; projected position %s.',
                rtrim(rtrim(number_format($suggestion['min_stock_level'], 2), '0'), '.'),
                rtrim(rtrim(number_format($suggestion['projected_position'], 2), '0'), '.'),
            );
    }
}
