<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * Pending job demand against stock and open purchase orders, per material.
 *
 * Lifted out of ProcurementStoresController::materialDemandForecast (Report
 * 78C) so the Desk's forecast drawer and the "Procurement Shortage" tile are
 * one calculation over one set of demand lines. Never reserves or allocates.
 */
class MaterialShortageForecast
{
    /**
     * @param  Collection<int, array<string, mixed>>  $pendingLines  from ProjectMaterialDemand::pendingLines()
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $pendingLines): Collection
    {
        if ($pendingLines->isEmpty()) {
            return collect();
        }

        $materialIds = $pendingLines->pluck('library_material_id')->unique()->values();
        $materials = LibraryMaterial::with('stock')
            ->whereIn('id', $materialIds)->get()->keyBy('id');

        $boardMaterialIds = $materials->filter(fn ($material) => $material->isBoardTrackable())
            ->pluck('id')->unique();
        $boardAvailable = Board::query()->whereIn('library_material_id', $boardMaterialIds)
            ->where('status', 'Available')->selectRaw('library_material_id, COUNT(*) AS quantity')
            ->groupBy('library_material_id')->pluck('quantity', 'library_material_id');

        $incoming = PurchaseOrderItem::query()
            ->whereNotNull('material_id')
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected', 'completed']))
            ->withSum('goodsReceiptNoteItems as received_total', 'received_quantity')
            ->get(['id', 'material_id', 'quantity'])
            ->groupBy('material_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($row) => max(0, (float) $row->quantity - (float) ($row->received_total ?? 0))));

        return $pendingLines->groupBy('library_material_id')->map(function ($materialLines, $materialId) use ($materials, $boardAvailable, $incoming) {
            $material = $materials->get($materialId);
            // `approved` is the word this screen has always used for the
            // specified quantity; the demand service calls it `specified`.
            $projectRows = $materialLines->map(fn (array $line) => [
                'project_id' => $line['project_id'],
                'project_code' => $line['project_code'],
                'project_title' => $line['project_title'],
                'project_material_id' => $line['project_material_id'],
                'element' => $line['element'],
                'approved' => $line['specified'],
                'issued' => $line['issued'],
                'retired' => $line['retired'] ?? 0,
                'pending' => $line['pending'],
                'required_by' => $line['required_by'],
                'lifecycle_state' => $line['lifecycle_state'] ?? null,
                'overdue' => $line['overdue'] ?? false,
            ])->values();

            $pending = (float) $projectRows->sum('pending');
            $reserved = (float) ($material?->stock?->quantity_reserved ?? 0);
            $available = $material?->isBoardTrackable()
                ? max(0, (float) ($boardAvailable[$materialId] ?? 0) - $reserved)
                : max(0, (float) ($material?->stock?->quantity_on_hand ?? 0) - $reserved);
            $incomingQuantity = (float) ($incoming[$materialId] ?? 0);
            $immediateShortage = max(0, $pending - $available);
            $projectedShortage = max(0, $pending - $available - $incomingQuantity);
            $status = $immediateShortage <= 0 ? 'fully_covered' : ($projectedShortage <= 0 ? 'covered_by_incoming' : ($available > 0 ? 'partially_covered' : 'shortage'));

            return [
                'material_id' => (int) $materialId,
                'material_name' => $material?->material_name ?? 'Material',
                'material_code' => $material?->material_code,
                'unit' => $material?->unit_of_measure,
                'pending_demand' => round($pending, 4),
                'available' => round($available, 4),
                'incoming' => round($incomingQuantity, 4),
                'immediate_shortage' => round($immediateShortage, 4),
                'projected_shortage' => round($projectedShortage, 4),
                'coverage_percent' => $pending > 0 ? min(100, round(($available / $pending) * 100, 1)) : 100,
                'earliest_required_by' => $projectRows->pluck('required_by')->filter()->sort()->first(),
                'status' => $status,
                'projects' => $projectRows,
            ];
        })->filter()->sortByDesc('projected_shortage')->values();
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function summary(Collection $rows): array
    {
        return [
            'materials' => $rows->count(),
            'fully_covered' => $rows->where('status', 'fully_covered')->count(),
            'at_risk' => $rows->whereIn('status', ['partially_covered', 'shortage'])->count(),
            'covered_by_incoming' => $rows->where('status', 'covered_by_incoming')->count(),
        ];
    }
}
