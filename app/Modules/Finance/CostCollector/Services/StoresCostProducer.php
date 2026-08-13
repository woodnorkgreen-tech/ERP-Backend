<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\ProcurementStores\Models\InventoryLog;

/** Stock check-outs to projects → actual material cost lines. */
class StoresCostProducer
{
    public function __construct(private CostCollectorService $collector) {}

    public function postStockIssue(InventoryLog $log): ?CostLine
    {
        if (! in_array($log->type, ['check_out', 'issue', 'consumption'], true)) {
            return null;
        }

        $enquiryId = $log->project_id;
        if (! $enquiryId && blank($log->reference_no)) {
            return null;
        }

        // `material.expenseCode` was eager-loaded here and no such relation
        // exists — library materials carry no expense code, only requisition
        // items do. Every stock issue therefore threw a RelationNotFound before
        // reaching the catalogue fallback below, which means stores spend never
        // produced a cost line at all.
        $log->loadMissing('material');
        $material = $log->material;
        $quantity = abs((float) $log->quantity);
        if ($quantity <= 0) {
            return null;
        }

        $unitCost = (string) ($log->receipt_unit_cost ?? $material?->unit_cost ?? '0.00');
        $amount = bcmul((string) $quantity, $unitCost, 2);
        if (bccomp($amount, '0.00', 2) !== 1) {
            return null;
        }

        $expenseCode = $this->expenseCodeFor($material);

        $planned = $this->plannedLine(
            $enquiryId,
            $log->reference_no,
            (int) $log->material_id,
            $log->project_material_id ? (int) $log->project_material_id : null,
        );

        return $this->collector->postFromSource(new CostContext(
            expenseCode: (string) $expenseCode,
            amount: $amount,
            nature: CostLine::NATURE_ACTUAL,
            enquiryId: $enquiryId ? (int) $enquiryId : null,
            jobNumber: $log->reference_no,
            sourceType: InventoryLog::class,
            sourceId: $log->id,
            sourceRef: 'stock-issue',
            sourceApproved: true,
            payeeName: $log->recipient_name ?? 'Storekeeper Issue',
            consumesLineId: $planned?->id,
            description: 'Stores Issue: ' . ($material?->material_name ?? "Item #{$log->material_id}") . " (x{$quantity})",
            details: array_filter([
                'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                'inventory_log_id' => $log->id,
                'library_material_id' => $log->material_id,
                'project_material_id' => $log->project_material_id,
                'batch_number' => $log->batch_number,
                'quantity' => (string) $quantity,
                'unit_cost' => $unitCost,
            ], fn ($value) => $value !== null),
        ));
    }

    /**
     * Post a signed, proportional credit for material physically returned to Stores.
     * The original cost and journal remain untouched; this new fact supplies the
     * audit trail and restores both project actuals and planned-line headroom.
     */
    public function postStockReturn(InventoryLog $return): ?CostLine
    {
        if ($return->type !== 'return' || ! $return->original_issue_log_id) {
            return null;
        }

        $return->loadMissing('material', 'originalIssue');
        $issue = $return->originalIssue;
        if (! $issue || (int) $issue->material_id !== (int) $return->material_id) {
            throw new \DomainException('The stock return is not linked to a matching original issue.');
        }

        $originalCost = CostLine::where('source_type', InventoryLog::class)
            ->where('source_id', $issue->id)
            ->where('source_ref', 'stock-issue')
            ->where('status', CostLine::STATUS_VERIFIED)
            ->first();
        if (! $originalCost) {
            throw new \DomainException('The original Stores issue has no verified project cost to credit.');
        }

        $issuedQuantity = abs((float) $issue->quantity);
        $returnedQuantity = abs((float) $return->quantity);
        if ($issuedQuantity <= 0 || $returnedQuantity <= 0) {
            return null;
        }

        $credit = bcmul(
            (string) $originalCost->net_amount,
            bcdiv((string) $returnedQuantity, (string) $issuedQuantity, 8),
            2,
        );
        if (bccomp($credit, '0.00', 2) !== 1) {
            return null;
        }

        $negative = '-' . $credit;
        $line = $this->collector->postFromSource(new CostContext(
            expenseCode: (string) $originalCost->expenseCode?->code,
            amount: $credit,
            nature: CostLine::NATURE_ACTUAL,
            enquiryId: $originalCost->project_enquiry_id,
            jobNumber: $originalCost->job_number,
            sourceType: InventoryLog::class,
            sourceId: $return->id,
            sourceRef: 'stock-return',
            sourceApproved: true,
            payeeName: 'Stores Return',
            consumesLineId: $originalCost->consumes_line_id,
            description: 'Stores Return: ' . ($return->material?->material_name ?? "Item #{$return->material_id}") . " (x{$returnedQuantity})",
            details: [
                'budget_category' => $originalCost->details['budget_category'] ?? 'materials',
                'inventory_log_id' => $return->id,
                'original_issue_log_id' => $issue->id,
                'original_cost_line_id' => $originalCost->id,
                'library_material_id' => $return->material_id,
                'project_material_id' => $return->project_material_id,
                'quantity' => (string) $returnedQuantity,
                'movement' => 'return_credit',
            ],
        ), [
            'amount' => $negative,
            'net_amount' => $negative,
            'base_net_amount' => bcmul($negative, (string) ($originalCost->fx_rate ?? 1), 2),
            'reversal_of_id' => $originalCost->id,
        ]);

        return $line;
    }

    private function plannedLine(?int $enquiryId, ?string $jobNumber, int $materialId, ?int $projectMaterialId = null): ?CostLine
    {
        if (! $enquiryId && blank($jobNumber)) {
            return null;
        }

        $base = CostLine::query()
            ->where('nature', CostLine::NATURE_PLANNED)
            ->where('status', CostLine::STATUS_VERIFIED)
            ->when($enquiryId, fn ($q) => $q->where('project_enquiry_id', $enquiryId))
            ->when(! $enquiryId && $jobNumber, fn ($q) => $q->where('job_number', $jobNumber))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.budget_category')) = 'materials'");

        // The exact approved project line wins. The same catalogue material may
        // legitimately appear in several elements; matching only its catalogue
        // id would consume whichever budget line happened to be created first.
        if ($projectMaterialId) {
            $exactLine = (clone $base)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.project_material_id')) = ?", [(string) $projectMaterialId])
                ->first();
            if ($exactLine) {
                return $exactLine;
            }
        }

        // Exact catalogue identity wins for legacy issues without a project-line
        // identity. This is a compatibility fallback, not the governed path.
        // library_material_id through budget and procurement instead of copying
        // only a description that can be misspelled or renamed later.
        return (clone $base)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.library_material_id')) = ?", [(string) $materialId])
            ->orderBy('id')
            ->first()
            ?? $base->orderBy('id')->first();
    }

    private function expenseCodeFor(?object $material): string
    {
        // Finance may govern a material-specific code in the master attributes.
        // It is optional for legacy catalogue rows; the direct-material code is
        // the deterministic fallback, never an arbitrary first active expense.
        $attributes = $material?->attributes ?? [];
        $attributes = $attributes['attributes'] ?? $attributes;
        $configured = $attributes['expense_code'] ?? $attributes['finance_expense_code'] ?? null;

        if ($configured && ExpenseCode::active()->where('code', $configured)->exists()) {
            return (string) $configured;
        }

        return (string) (ExpenseCode::active()->where('code', 'DM-WD-001')->value('code')
            ?? ExpenseCode::active()->where('expense_type', 'like', '%material%')->orderBy('code')->value('code')
            ?? throw new \DomainException('No active direct-material expense code is configured for Stores issues.'));
    }
}
