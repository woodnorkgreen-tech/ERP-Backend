<?php

namespace App\Services;

use App\Modules\Projects\Models\EnquiryTask;
use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Models\BudgetVersion;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * Provenance stamp written into `materials_import_metadata.source` by the
     * one importer that exists. Procurement gates on this value.
     */
    public const IMPORT_SOURCE_APPROVED_MATERIALS = 'approved_materials_list';

    /**
     * Get budget data for a task
     */
    /**
     * The budget, pulling the approved materials list if it has never had one.
     *
     * This closes the second half of the drift problem. Approval pushes into an
     * existing budget, but on plenty of projects the materials list is approved
     * before the budget task is ever opened — there was nothing to push into, so
     * the budget opened empty and somebody had to know to press Sync. Pulling on
     * first read means a budget is never empty because of ordering.
     *
     * Only ever on the FIRST read (`materials_imported_at` is null). After that
     * the push owns it; pulling again here would silently overwrite whatever the
     * costing team is part-way through entering.
     */
    public function getBudgetData(int $taskId): ?TaskBudgetData
    {
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        if ($budgetData && $budgetData->materials_imported_at) {
            return $budgetData;
        }

        try {
            return $this->syncFromMaterialsList($taskId)['budget'];
        } catch (\Throwable $e) {
            // No materials task, nothing approved yet, no source data — all
            // ordinary states for a project this early. The budget is returned as
            // it stands rather than failing a read over it.
            return $budgetData;
        }
    }

    /**
     * Save/Update budget data
     */
    public function saveBudgetData(int $taskId, array $data): TaskBudgetData
    {
        $budgetData = DB::transaction(function () use ($taskId, $data) {
            $existingBudgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
            $status = $this->resolveSaveStatus($existingBudgetData, $data['status'] ?? null);

            $budgetData = TaskBudgetData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $data['projectInfo'],
                    'materials_data' => $data['materials'] ?? [],
                    'labour_data' => $data['labour'] ?? [],
                    'expenses_data' => $data['expenses'] ?? [],
                    'logistics_data' => $data['logistics'] ?? [],
                    'budget_summary' => $this->calculateSummary($data),
                    'status' => $status
                ]
            );

            return $budgetData;
        });

        // Procurement is a downstream consumer, not part of the budget write —
        // run it after the commit so a procurement problem can never roll back
        // a saved budget.
        $this->syncProcurementWithBudget($taskId);

        return $budgetData;
    }

    /**
     * Push the current budget figures into this project's procurement task.
     *
     * Procurement already pulls the budget when someone opens the task
     * (ProcurementService::getProcurementData), but that leaves it stale for
     * every other reader — dashboards, reports, exports — until a human
     * happens to open it. Pushing on each budget write keeps the two in step.
     *
     * syncWithBudget() no-ops when procurement has never imported the budget,
     * preserves user-entered procurement state when merging, and swallows its
     * own failures, so this is safe to call unconditionally.
     */
    private function syncProcurementWithBudget(int $budgetTaskId): void
    {
        $budgetTask = EnquiryTask::find($budgetTaskId);
        if (!$budgetTask) {
            return;
        }

        $procurementTask = EnquiryTask::where('project_enquiry_id', $budgetTask->project_enquiry_id)
            ->where('type', 'procurement')
            ->first();

        if (!$procurementTask) {
            return;
        }

        app(ProcurementService::class)->syncWithBudget($procurementTask->id);
    }

    /**
     * Bring the budget's material list back in line with the approved one.
     *
     * There used to be two implementations of this reconciliation — one here,
     * driven by a Sync button, and one in `MaterialsController`, driven by
     * approval — matching rows by six different identity schemes between them.
     * Two reconcilers over one fact is how the copies came to disagree in the
     * first place, and a button asking a human to notice the disagreement is not
     * a fix for it. This is now the only one, and the system calls it rather than
     * a person pressing it.
     *
     * The budget owns exactly one thing the materials list does not: the internal
     * rate. That is what survives a sync, keyed on `persistent_id` — a stable
     * identity, not a description that changes the moment somebody fixes a typo.
     * Everything else (what, how much, whether it is included) belongs to the
     * approved materials list and is taken from it wholesale, which is what makes
     * "out of sync" a state this system can no longer be in.
     *
     * @return array{budget: TaskBudgetData, reopened: bool, message: string}
     */
    public function syncFromMaterialsList(int $budgetTaskId): array
    {
        $task = EnquiryTask::with('enquiry.client')->findOrFail($budgetTaskId);

        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (! $materialsTask) {
            throw new \Exception('No materials task found for this project enquiry');
        }

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
            ->with(['elements.materials.libraryMaterial'])
            ->first();

        if (! $materialsData) {
            throw new \Exception('Source materials data not found');
        }

        // Materials flow into the budget as they are added. Departmental sign-off
        // is recorded on the materials task for audit, but blocking the import on
        // it meant a single new line stalled the whole budget until two people
        // re-approved a list they had already approved.
        $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTaskId)->first();

        // The approved figures are about to change under anyone relying on a
        // "complete" badge — Finance gates, procurement — so reopen it rather than
        // letting the status assert a sign-off of numbers nobody has seen.
        $wasCompleted = $task->status === 'completed';
        if ($wasCompleted) {
            $task->update(['status' => 'in_progress', 'completed_at' => null]);
            $task->recordCustomAction('status_transition', [
                'from' => 'completed',
                'to' => 'in_progress',
                'actor_type' => auth()->id() ? 'user' : 'system',
                'actor_id' => auth()->id(),
                'reason' => 'The approved materials list changed, so this budget was reopened and re-synced. Review the updated totals.',
            ]);
        }

        $materials = $this->transformMaterialsToBudget($materialsData);
        $this->applyExistingBudgetPrices($budgetData->materials_data ?? [], $materials);

        $projectInfo = $budgetData->project_info ?? [
            'projectId' => $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? '',
            'enquiryTitle' => $task->enquiry->title ?? '',
            'clientName' => $task->enquiry->client->full_name ?? '',
            'eventVenue' => $task->enquiry->venue ?? '',
            'setupDate' => $task->enquiry->expected_delivery_date ?? '',
        ];

        $summary = $this->calculateSummary([
            'materials' => $materials,
            'labour' => $budgetData->labour_data ?? [],
            'expenses' => $budgetData->expenses_data ?? [],
            'logistics' => $budgetData->logistics_data ?? [],
        ]);

        $budgetData = DB::transaction(fn () => TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $budgetTaskId],
            [
                'project_info' => $projectInfo,
                'materials_data' => $materials,
                'budget_summary' => $summary,
                'materials_imported_at' => now(),
                'last_import_date' => now(),
                'materials_imported_from_task' => $materialsTask->id,
                // Stamp where these rows came from. Downstream consumers
                // (procurement's readiness gate, the budget UI's import banner)
                // read this to tell an approved-list budget from an ad-hoc one,
                // and it went unwritten for long enough that every reader had
                // learned to treat "absent" as "unknown" rather than "bad".
                'materials_import_metadata' => [
                    'source' => self::IMPORT_SOURCE_APPROVED_MATERIALS,
                    'materials_task_id' => $materialsTask->id,
                    'imported_at' => now()->toISOString(),
                    'element_count' => count($materials),
                ],
            ],
        ));

        // A sync rewrites exactly the rows procurement mirrors, so push it through
        // rather than leaving a third copy stale.
        $this->syncProcurementWithBudget($budgetTaskId);

        return [
            'budget' => $budgetData,
            'reopened' => $wasCompleted,
            'message' => $wasCompleted
                ? 'Budget re-synced with the approved materials list, and reopened for review.'
                : 'Budget is in line with the approved materials list.',
        ];
    }

    /**
     * Internal helper to generate variance analysis between two material sets
     */
    protected function analyzeMaterialVariances(array $baseMaterials, array $currMaterials): array
    {
        $variances = [];
        $totalVariance = 0;
        $volVar = 0;
        $priceVar = 0;

        $getKey = function($elem, $mat) {
            $pid = $mat['persistent_id'] ?? null;
            if ($pid) return $pid;
            
            // Stable Fallback: Normalized Element Name + Description
            $elemName = strtolower(preg_replace('/\s+/', '', ($elem['name'] ?? '')));
            $matDesc = strtolower(preg_replace('/\s+/', '', ($mat['description'] ?? '')));
            return "legacy_{$elemName}_{$matDesc}";
        };

        $baseMap = [];
        foreach ($baseMaterials as $elem) {
            foreach ($elem['materials'] ?? [] as $mat) {
                $key = $getKey($elem, $mat);
                $baseMap[$key] = ['mat' => $mat, 'elem' => $elem];
            }
        }

        $handledKeys = [];

        foreach ($currMaterials as $inElem) {
            foreach ($inElem['materials'] ?? [] as $inMat) {
                $key = $getKey($inElem, $inMat);
                $handledKeys[] = $key;
                $matching = $baseMap[$key] ?? null;

                $Qb = $matching ? ($matching['mat']['quantity'] ?? 0) : 0;
                $Pb = $matching ? ($matching['mat']['unitPrice'] ?? 0) : 0;
                $Qc = $inMat['quantity'] ?? 0;
                $Pc = $inMat['unitPrice'] ?? 0;

                $variance = ($Qc * $Pc) - ($Qb * $Pb);
                if (abs($variance) < 0.01) continue;

                $ivolVar = ($Qc - $Qb) * $Pb;
                $ipriceVar = ($Pc - $Pb) * $Qc;

                $variances[] = [
                    'description' => "[{$inElem['name']}] {$inMat['description']}",
                    'comparisonKey' => $key,
                    'baselineQty' => $Qb,
                    'currentQty' => $Qc,
                    'unit' => $inMat['unitOfMeasurement'],
                    'library_material_id' => $inMat['library_material_id'] ?? null,
                    'variance' => $variance,
                    'volumeVariance' => $ivolVar,
                    'priceVariance' => $ipriceVar,
                    'isNew' => !$matching
                ];

                $totalVariance += $variance;
                $volVar += $ivolVar;
                $priceVar += $ipriceVar;
            }
        }

        // Removed items
        foreach ($baseMap as $key => $rem) {
            if (!in_array($key, $handledKeys)) {
                $var = -($rem['mat']['quantity'] * $rem['mat']['unitPrice']);
                $variances[] = [
                    'description' => "[{$rem['elem']['name']}] {$rem['mat']['description']}",
                    'comparisonKey' => $key,
                    'baselineQty' => $rem['mat']['quantity'],
                    'currentQty' => 0,
                    'unit' => $rem['mat']['unitOfMeasurement'],
                    'library_material_id' => $rem['mat']['library_material_id'] ?? null,
                    'variance' => $var,
                    'volumeVariance' => $var,
                    'priceVariance' => 0,
                    'isRemoved' => true
                ];
                $totalVariance += $var;
                $volVar += $var;
            }
        }

        return [
            'variances' => $variances,
            'summary' => [
                'totalVariance' => $totalVariance,
                'volumeVariance' => $volVar,
                'priceVariance' => $priceVar
            ]
        ];
    }

    /**
     * Submit budget for approval
     */
    private function resolveSaveStatus(?TaskBudgetData $existingBudgetData, ?string $requestedStatus): string
    {
        // Internal budget approval was removed (2026-07-07): budgets stay
        // 'draft' and the budget task auto-completes once a priced summary is
        // saved. Legacy statuses on existing rows are preserved when no new
        // status is requested.
        if ($requestedStatus === 'draft') {
            return 'draft';
        }

        return $existingBudgetData?->status ?? 'draft';
    }

    /**
     * Carry the budget's own rates across a sync.
     *
     * Keyed on `persistent_id` alone. There used to be a fallback that matched on
     * element name plus material description, from when identity was unstable —
     * but a description key silently moves a rate onto a different material as
     * soon as someone fixes a typo, and a wrong rate that looks deliberate is
     * worse than a blank one somebody has to fill in.
     *
     * @param  array<int, mixed>  $existingMaterials
     * @param  array<int, mixed>  $incomingMaterials
     */
    private function applyExistingBudgetPrices(array $existingMaterials, array &$incomingMaterials): void
    {
        $rates = [];

        foreach ($existingMaterials as $element) {
            foreach ($element['materials'] ?? [] as $material) {
                if (! empty($material['persistent_id'])) {
                    $rates[(string) $material['persistent_id']] = (float) ($material['unitPrice'] ?? 0);
                }
            }
        }

        foreach ($incomingMaterials as &$element) {
            foreach ($element['materials'] as &$material) {
                $rate = $rates[(string) ($material['persistent_id'] ?? '')] ?? null;

                if ($rate === null || $rate <= 0) {
                    continue;
                }

                // Quantity always comes from the approved list; only the rate is
                // the budget's to keep. So the total is recomputed rather than
                // carried, and there is no "quantity changed" flag to raise —
                // the two lists cannot disagree about quantity any more.
                $material['unitPrice'] = $rate;
                $material['totalPrice'] = (float) ($material['quantity'] ?? 0) * $rate;
            }
        }
    }

    /**
     * Internal helper to transform materials structure
     */
    protected function transformMaterialsToBudget(TaskMaterialsData $data): array
    {
        $results = [];
        foreach ($data->elements as $element) {
            if (!$element->is_included) continue;
            
            $budgetMaterials = [];
            foreach ($element->materials as $material) {
                if (!$material->is_included) continue;
                
                // Seeded from the material's own cost, falling back to the
                // library's. Starting every row at zero would make a sync look
                // like it had wiped the budget, and would hand the costing team a
                // blank sheet when the library already knows what these things
                // cost. Their own entered rate still wins — that is applied over
                // the top by applyExistingBudgetPrices().
                $seedRate = (float) ($material->unit_cost
                    ?: ($material->libraryMaterial->unit_cost ?? 0));

                $budgetMaterials[] = [
                    'id' => $material->persistent_id ?: uniqid('mat_'),
                    'persistent_id' => $material->persistent_id,
                    'library_material_id' => $material->library_material_id,
                    'description' => $material->description,
                    'unitOfMeasurement' => $material->unit_of_measurement,
                    'quantity' => $material->quantity,
                    'unitPrice' => $seedRate,
                    'totalPrice' => $seedRate * (float) $material->quantity,
                    'category' => $element->category,
                    'is_included' => true,
                ];
            }

            if (!empty($budgetMaterials)) {
                $results[] = [
                    'id' => $element->persistent_id ?: uniqid('elem_'),
                    'persistent_id' => $element->persistent_id,
                    'name' => $element->name,
                    'category' => $element->category,
                    'materials' => $budgetMaterials
                ];
            }
        }
        return $results;
    }

    /**
     * Calculate comprehensive summary for budget
     */
    protected function calculateSummary(array $data): array
    {
        $mTotal = 0;
        foreach ($data['materials'] ?? [] as $elem) {
            // Respect inclusion flags at group level
            $elIncluded = $elem['isIncluded'] ?? $elem['is_included'] ?? true;
            if (!$elIncluded) continue;

            foreach ($elem['materials'] ?? [] as $mat) {
                // Respect inclusion flags at item level
                $matIncluded = $mat['isIncluded'] ?? $mat['is_included'] ?? true;
                if (!$matIncluded) continue;

                $mTotal += (float)($mat['totalPrice'] ?? 0);
            }
        }

        $lTotal = array_reduce($data['labour'] ?? [], function($s, $i) {
            $included = $i['isIncluded'] ?? $i['is_included'] ?? true;
            return $s + ($included ? (float)($i['amount'] ?? 0) : 0);
        }, 0);

        $eTotal = array_reduce($data['expenses'] ?? [], function($s, $i) {
            $included = $i['isOfScope'] ?? $i['is_included'] ?? true;
            return $s + ($included ? (float)($i['amount'] ?? 0) : 0);
        }, 0);

        $logTotal = array_reduce($data['logistics'] ?? [], function($s, $i) {
            $included = $i['isIncluded'] ?? $i['is_included'] ?? true;
            return $s + ($included ? (float)($i['amount'] ?? 0) : 0);
        }, 0);

        return [
            'materialsTotal' => $mTotal,
            'labourTotal' => $lTotal,
            'expensesTotal' => $eTotal,
            'logisticsTotal' => $logTotal,
            'grandTotal' => $mTotal + $lTotal + $eTotal + $logTotal
        ];
    }

}
