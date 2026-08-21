<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\ProcurementStores\Models\InventoryLog;
use Illuminate\Support\Facades\DB;

/** Stock check-outs to projects → actual material cost lines. */
class StoresCostProducer
{
    public function __construct(private CostCollectorService $collector) {}

    public function postStockIssue(InventoryLog $log): ?CostLine
    {
        if (! in_array($log->type, ['check_out', 'issue', 'consumption'], true)) {
            return null;
        }

        // inventory_logs.project_id is a Projects primary key. It was previously
        // passed straight through as the enquiry id, which silently charged the
        // cost to whichever enquiry happened to share that number — a different
        // project entirely. Resolve the whole identity from the project itself.
        $identity = $this->identityFor($log);
        if (! $identity['project_id'] && ! $identity['project_enquiry_id'] && blank($identity['job_number'])) {
            return null;
        }

        // `material.expenseCode` was eager-loaded here and no such relation
        // exists — library materials carry no expense code, only requisition
        // items do. Every stock issue therefore threw a RelationNotFound before
        // reaching the catalogue fallback below, which means stores spend never
        // produced a cost line at all.
        $log->loadMissing('material.materialCategory.parent');
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
            $identity['project_enquiry_id'],
            $identity['job_number'],
            (int) $log->material_id,
            $log->project_material_id ? (int) $log->project_material_id : null,
        );

        return $this->collector->postFromSource(new CostContext(
            expenseCode: (string) $expenseCode,
            amount: $amount,
            nature: CostLine::NATURE_ACTUAL,
            projectId: $identity['project_id'],
            enquiryId: $identity['project_enquiry_id'],
            jobNumber: $identity['job_number'],
            sourceType: InventoryLog::class,
            sourceId: $log->id,
            sourceRef: 'stock-issue',
            sourceApproved: true,
            payeeName: $log->recipient_name ?? 'Storekeeper Issue',
            consumesLineId: $planned?->id,
            description: 'Stores Issue: ' . ($material?->material_name ?? "Item #{$log->material_id}") . " (x{$quantity})",
            details: array_filter([
                'budget_category' => $planned?->details['budget_category'] ?? 'materials',
                // Inherited from the plan where there is one, resolved from the
                // project material line where there is not — an unbudgeted issue
                // still belongs to an element, and dropping it there would put
                // exactly the spend worth grouping outside the grouping.
                'element' => $planned?->details['element']
                    ?? $this->elementNameFor($log->project_material_id ? (int) $log->project_material_id : null),
                'material' => $material?->material_name,
                'inventory_log_id' => $log->id,
                'library_material_id' => $log->material_id,
                'project_material_id' => $log->project_material_id,
                // The reference Stores actually wrote on the movement. It is the
                // project display code, which drifts from the enquiry job number
                // on most projects, so it is kept as provenance rather than
                // published as the cost line's job identity.
                'stores_reference' => $log->reference_no,
                'batch_number' => $log->batch_number,
                'quantity' => (string) $quantity,
                'unit_cost' => $unitCost,
                'unbudgeted' => $planned ? null : true,
                // Surfaces the mapping gap instead of letting non-wood spend
                // disappear into the wood account unremarked.
                'unmapped_expense_code' => $this->usesDefaultExpenseCode($material, (string) $expenseCode) ?: null,
            ], fn ($value) => $value !== null),
        ));
    }

    /**
     * The element a project material line belongs to.
     *
     * Read from the operational record rather than parsed back out of the cost
     * line's description: the description is a sentence built for a human, and
     * re-deriving structure from it is how the element got lost in the first
     * place.
     */
    public function elementNameFor(?int $projectMaterialId): ?string
    {
        if (! $projectMaterialId) {
            return null;
        }

        $name = ElementMaterial::with('element:id,name')->find($projectMaterialId)?->element?->name;

        return filled($name) ? (string) $name : null;
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
        // A reviewed quarantine return may recover less value than an intact
        // unit. receipt_unit_cost on a return is the reviewer-approved recovery
        // value per returned unit, capped at the original proportional credit.
        if ($return->receipt_unit_cost !== null) {
            $accepted = bcmul((string) $returnedQuantity, (string) $return->receipt_unit_cost, 2);
            if (bccomp($accepted, $credit, 2) === -1) $credit = $accepted;
        }
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
                'element' => $originalCost->details['element'] ?? null,
                'material' => $originalCost->details['material'] ?? null,
                'inventory_log_id' => $return->id,
                'original_issue_log_id' => $issue->id,
                'original_cost_line_id' => $originalCost->id,
                'library_material_id' => $return->material_id,
                'project_material_id' => $return->project_material_id,
                'quantity' => (string) $returnedQuantity,
                'movement' => 'return_credit',
                // Which kind of credit this is. A whole item came back unused —
                // the project no longer needs it and its requirement reopens. A
                // recovered offcut is the usable remnant of a board the project
                // did consume: it reduces cost without meaning another board is
                // owed. Both used to post as an unlabelled negative, so a board
                // that came back in part and a board that came back whole were
                // the same figure on the account, and neither explained why the
                // material had cost less than it was issued at.
                'return_kind' => $return->return_kind
                    ?: (str_starts_with((string) $return->notes, 'Offcut ') ? 'recovered_offcut' : 'whole_item'),
            ],
        ), [
            'amount' => $negative,
            'net_amount' => $negative,
            'base_net_amount' => bcmul($negative, (string) ($originalCost->fx_rate ?? 1), 2),
            'reversal_of_id' => $originalCost->id,
        ]);

        return $line;
    }

    /**
     * The movement's own project is the authority. Its enquiry and the canonical
     * job number are read from that project rather than inferred from anything
     * Stores wrote on the movement, because a Stores reference is a project
     * display code and those drift from enquiry job numbers.
     *
     * @return array{project_id: ?int, project_enquiry_id: ?int, job_number: ?string}
     */
    private function identityFor(InventoryLog $log): array
    {
        if ($log->project_id) {
            $project = Project::with('enquiry')->find($log->project_id);

            if ($project) {
                return [
                    'project_id' => (int) $project->id,
                    'project_enquiry_id' => $project->enquiry_id ? (int) $project->enquiry_id : null,
                    'job_number' => $project->enquiry?->job_number ?: $project->project_id,
                ];
            }
        }

        // Legacy board issues carry only the job reference. Hand it over as a
        // job number — never as an id — and let the collector resolve the rest.
        return [
            'project_id' => null,
            'project_enquiry_id' => null,
            'job_number' => blank($log->reference_no) ? null : $log->reference_no,
        ];
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
        //
        // The two sides key the same line differently: the budget projector
        // writes element_materials.persistent_id (a UUID), while the movement
        // carries element_materials.id (an integer). Comparing them directly
        // could never match, which is what pushed every issue down to the blind
        // fallback below. Try both notations.
        if ($projectMaterialId) {
            $keys = array_values(array_filter([
                (string) $projectMaterialId,
                ElementMaterial::whereKey($projectMaterialId)->value('persistent_id'),
            ]));

            $exactLine = (clone $base)
                ->whereIn(
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.project_material_id'))"),
                    $keys,
                )
                ->first();
            if ($exactLine) {
                return $exactLine;
            }
        }

        // Exact catalogue identity is the one permitted fallback, for legacy
        // issues that predate project-line identity. It is still an exact match
        // on the material actually issued.
        //
        // There is deliberately no fallback beyond this. Charging an unmatched
        // issue to whichever materials line sorted first meant a movement could
        // silently consume an unrelated budget line — the failure that put two
        // MDF issues against another project's budget. An issue with no exact
        // planned line is unbudgeted spend, which the Cost Collector is built to
        // surface immediately rather than hide behind an arbitrary match.
        return (clone $base)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.library_material_id')) = ?", [(string) $materialId])
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve the expense code Finance governs for this material, in descending
     * order of authority: the material's own configured code, then its category's
     * mapped code, then the configured default for unmapped materials.
     *
     * DM-WD-001 is *wood*. Using it for every material — adhesives, fabric,
     * fixings, print media — classified spend that Finance then has to unpick by
     * hand. It survives only as the last-resort default, and only because
     * refusing to post would strand real stock movements outside project cost;
     * lines that land on it are marked so the mapping gap is visible rather than
     * silently absorbed into the wood account.
     */
    private function expenseCodeFor(?object $material): string
    {
        $attributes = $material?->attributes ?? [];
        $attributes = $attributes['attributes'] ?? $attributes;
        $configured = $attributes['expense_code'] ?? $attributes['finance_expense_code'] ?? null;

        if ($configured && ExpenseCode::active()->where('code', $configured)->exists()) {
            return (string) $configured;
        }

        // Category-level mapping is how Finance governs this at scale: one entry
        // per material category rather than per catalogue row.
        $categoryMap = (array) config('cost-collector.material_category_expense_codes', []);
        $categoryNames = array_values(array_filter([
            $material?->materialCategory?->name,
            $material?->materialCategory?->parent?->name,
            $material?->category,
        ]));

        foreach ($categoryNames as $name) {
            $mapped = $categoryMap[$name] ?? null;
            if ($mapped && ExpenseCode::active()->where('code', $mapped)->exists()) {
                return (string) $mapped;
            }
        }

        $default = (string) config('cost-collector.default_material_expense_code', 'DM-WD-001');

        return (string) (ExpenseCode::active()->where('code', $default)->value('code')
            ?? ExpenseCode::active()->where('expense_type', 'like', '%material%')->orderBy('code')->value('code')
            ?? throw new \DomainException('No active direct-material expense code is configured for Stores issues.'));
    }

    /** True when the material has no governed code and fell through to the default. */
    private function usesDefaultExpenseCode(?object $material, string $resolved): bool
    {
        return $resolved === (string) config('cost-collector.default_material_expense_code', 'DM-WD-001');
    }
}
