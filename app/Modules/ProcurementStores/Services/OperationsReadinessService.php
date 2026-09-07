<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Services\MaterialPurchaseOptions;
use App\Modules\MaterialsLibrary\Support\MaterialCompleteness;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Models\Supplier;

/** Read-only checks against current master data and transaction records. */
class OperationsReadinessService
{
    public function report(): array
    {
        $materials = LibraryMaterial::governed()->with([
            'materialCategory.parent', 'baseUom', 'purchaseUom', 'issueUom', 'uomConversions', 'stock',
        ])->get();
        $incomplete = $materials->filter(fn ($material) =>
            ! MaterialCompleteness::isComplete($material)
            || ! MaterialControl::compatible((string) $material->issue_disposition, (string) $material->tracking_mode)
            || ! $material->baseUom?->is_active
            || ! $material->materialCategory?->is_active);

        $units = $materials->filter(function ($material) {
            if (app(MaterialPurchaseOptions::class)->forMaterial($material)['purchase_setup_warning']) return true;
            if (! $material->issue_uom_id || (int) $material->issue_uom_id === (int) $material->base_uom_id) return false;
            return ! $material->issueUom?->is_active || $material->is_serialized || $material->isBoardTrackable()
                || ! $material->uomConversions->contains(fn ($row) =>
                    (int) $row->from_uom_id === (int) $material->issue_uom_id
                    && (int) $row->to_uom_id === (int) $material->base_uom_id && (float) $row->factor > 0);
        });
        $unpriced = $materials->filter(fn ($material) =>
            ! $material->isBoardTrackable() && (float) $material->stock?->quantity_on_hand > 0
            && (float) $material->unit_cost <= 0 && (float) $material->default_unit_cost <= 0);
        $stockErrors = Stock::where('quantity_on_hand', '<', 0)->orWhere('quantity_reserved', '<', 0)
            ->orWhereColumn('quantity_reserved', '>', 'quantity_on_hand')->count();
        $unvaluedBoards = Board::available()->where(fn ($query) =>
            $query->whereNull('current_value')->orWhere('current_value', '<=', 0))->count();
        $unlabelledBoards = Board::available()->where('label_printed', false)->count();
        $failedPostings = StoresFinancePosting::where('status', 'failed')
            ->orWhere(fn ($q) => $q->where('status', 'pending')->where('updated_at', '<', now()->subMinutes(15)))
            ->orWhere(fn ($q) => $q->where('status', 'processing')->where(fn ($stale) =>
                $stale->whereNull('processing_started_at')->orWhere('processing_started_at', '<', now()->subMinutes(10))))
            ->count();

        $checks = [
            $this->check('catalogue', 'Active material catalogue', $materials->isNotEmpty(), $materials->count().' active materials available.',
                'Register materials and complete their setup before activation.', '/materials-library'),
            $this->check('material_controls', 'Material handling setup', $incomplete->isEmpty(), $incomplete->count().' active materials need setup corrections.',
                'Complete categories, units, required specifications and compatible handling controls.', '/materials-library', $incomplete->pluck('material_code')->take(10)->all()),
            $this->check('unit_conversions', 'Buying and issuing units', $units->isEmpty(), $units->count().' materials need unit corrections.',
                'Configure conversions to the stock unit; individually tracked items use the stock unit.', '/materials-library', $units->pluck('material_code')->take(10)->all()),
            $this->check('suppliers', 'Active suppliers', Supplier::where('status', 'active')->exists(), Supplier::where('status', 'active')->count().' active suppliers available.',
                'Register the suppliers needed for purchasing.', '/procurement/suppliers'),
            $this->check('stock_balances', 'Stock and reservations', $stockErrors === 0, $stockErrors.' invalid stock balances or reservations.',
                'Reconcile stock counts and reservations before issuing.', '/stores/stock-counts'),
            $this->check('stock_valuation', 'Stock valuation', $unpriced->isEmpty(), $unpriced->count().' stocked materials have no valuation.',
                'Record supported receipt or opening values before issuing to projects.', '/stores/inventory', $unpriced->pluck('material_code')->take(10)->all()),
            $this->check('board_valuation', 'Board valuation', $unvaluedBoards === 0, $unvaluedBoards.' available boards have no value.',
                'Record receipt valuation for available boards.', '/stores/inventory'),
            $this->check('board_labels', 'Board identification', $unlabelledBoards === 0, $unlabelledBoards.' available boards await label confirmation.',
                'Print and confirm physical board labels before allocation.', '/stores/inventory'),
            $this->check('stores_cost_capture', 'Stores to Finance cost capture', $failedPostings === 0, $failedPostings.' failed or stalled cost postings.',
                'Review exceptions, resolve valuation or project links, and retry posting.', '/stores/alerts'),
        ];

        return [
            'ready' => collect($checks)->every(fn ($check) => $check['ready']),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'draft_materials' => LibraryMaterial::where('item_status', 'Under Review')->count(),
            'pending_cost_postings' => StoresFinancePosting::whereIn('status', ['pending', 'processing'])->count(),
        ];
    }

    private function check(string $key, string $label, bool $ready, string $message, string $directive, string $path, array $examples = []): array
    {
        return compact('key', 'label', 'ready', 'message', 'directive', 'path', 'examples');
    }
}
