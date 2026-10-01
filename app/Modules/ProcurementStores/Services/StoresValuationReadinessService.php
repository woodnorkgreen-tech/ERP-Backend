<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;

class StoresValuationReadinessService
{
    public const VALUED = 'VALUED';

    public const UNVALUED = 'UNVALUED';

    public const REQUIRES_REVIEW = 'VALUATION_REQUIRES_REVIEW';

    public function project(): array
    {
        $stocks = Stock::with('material')
            ->where('quantity_on_hand', '>', 0)
            ->whereHas('material', fn ($query) => $query->where('is_active', true))
            ->orderBy('material_id')
            ->get();

        $receiptEvidence = InventoryLog::query()
            ->whereIn('material_id', $stocks->pluck('material_id'))
            ->where(function ($query) {
                $query->where('type', 'check_in')
                    ->orWhere(fn ($opening) => $opening->where('type', 'adjustment')->where('quantity', '>', 0));
            })
            ->selectRaw('material_id, SUM(CASE WHEN receipt_unit_cost > 0 THEN 1 ELSE 0 END) AS priced_receipts, SUM(CASE WHEN receipt_unit_cost IS NULL OR receipt_unit_cost <= 0 THEN 1 ELSE 0 END) AS unpriced_receipts')
            ->groupBy('material_id')
            ->get()
            ->keyBy('material_id');

        $items = $stocks->map(function (Stock $stock) use ($receiptEvidence) {
            $material = $stock->material;
            $onHand = (float) $stock->quantity_on_hand;
            $unitCost = (float) ($material?->unit_cost ?? 0);
            $evidence = $receiptEvidence->get($stock->material_id);
            $priced = (int) ($evidence?->priced_receipts ?? 0);
            $unpriced = (int) ($evidence?->unpriced_receipts ?? 0);

            if ($unitCost <= 0) {
                $classification = self::UNVALUED;
                $reason = 'No positive moving weighted average is recorded.';
            } elseif ($priced === 0 || $unpriced > 0) {
                $classification = self::REQUIRES_REVIEW;
                $reason = $priced === 0
                    ? 'A positive catalogue cost exists without a priced receipt audit trail.'
                    : 'Some receipt or opening movements have no authoritative unit cost.';
            } else {
                $classification = self::VALUED;
                $reason = 'Moving weighted average is supported by priced receipt evidence.';
            }

            return [
                'material_id' => $stock->material_id,
                'material_code' => $material?->material_code,
                'material_name' => $material?->material_name,
                'quantity_on_hand' => $onHand,
                'classification' => $classification,
                'unit_cost' => $classification === self::VALUED ? $unitCost : null,
                'authoritative_value' => $classification === self::VALUED ? round($onHand * $unitCost, 2) : null,
                'audit' => [
                    'priced_receipts' => $priced,
                    'unpriced_receipts' => $unpriced,
                    'recorded_moving_average' => $unitCost > 0 ? $unitCost : null,
                    'reason' => $reason,
                ],
            ];
        })->values();

        $counts = $items->countBy('classification');

        return [
            'summary' => [
                'materials' => $items->count(),
                'valued' => (int) ($counts[self::VALUED] ?? 0),
                'unvalued' => (int) ($counts[self::UNVALUED] ?? 0),
                'requires_review' => (int) ($counts[self::REQUIRES_REVIEW] ?? 0),
                'authoritative_inventory_value' => round((float) $items->sum('authoritative_value'), 2),
            ],
            'data' => $items,
        ];
    }
}
