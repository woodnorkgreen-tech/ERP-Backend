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
     * Get budget data for a task
     */
    public function getBudgetData(int $taskId): ?TaskBudgetData
    {
        return TaskBudgetData::where('enquiry_task_id', $taskId)->first();
    }

    /**
     * Save/Update budget data
     */
    public function saveBudgetData(int $taskId, array $data): TaskBudgetData
    {
        return DB::transaction(function () use ($taskId, $data) {
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
    }

    /**
     * Import materials from original Enquiry Task
     */
    public function importMaterials(int $taskId, bool $force = false): array
    {
        $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        
        // Find the materials task for this project
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) {
            throw new \Exception('No materials task found for this project enquiry');
        }

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
            ->with(['elements.materials'])
            ->first();

        if (!$materialsData) {
            throw new \Exception('Source materials data not found');
        }

        $this->ensureMaterialsApproved($materialsData);

        // A manual Sync deliberately rewrites the budget's numbers, but if the
        // task was already marked complete that rewrite must not happen
        // silently under a "done" status — reopen it so it's visibly back
        // under review.
        $wasCompleted = $task->status === 'completed';
        if ($wasCompleted) {
            $task->update(['status' => 'in_progress', 'completed_at' => null]);
            $task->recordCustomAction('status_transition', [
                'from' => 'completed',
                'to' => 'in_progress',
                'actor_type' => auth()->id() ? 'user' : 'system',
                'actor_id' => auth()->id(),
                'reason' => 'Reopened: materials list was manually re-synced into an already-completed budget.',
            ]);
        }

        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        
        // If already imported and not forced, we might want to merge or skip
        // For simplicity now, we overwrite but preserve existing price if it matches
        $existingMaterials = $budgetData ? ($budgetData->materials_data ?? []) : [];
        $newMaterials = $this->transformMaterialsToBudget($materialsData);

        // Preserve pricing logic (only if not forced). Stable material identity wins;
        // description is only a legacy fallback for older imported rows.
        if (!$force) {
            $pricedMap = [];
            foreach ($existingMaterials as $elem) {
                foreach ($elem['materials'] ?? [] as $mat) {
                    if (($mat['unitPrice'] ?? 0) > 0) {
                        $key = $this->budgetMaterialKey($elem, $mat);
                        $pricedMap[$key] = [
                            'unitPrice' => (float) $mat['unitPrice'],
                            'library_material_id' => $mat['library_material_id'] ?? null,
                            'quantity' => (float) ($mat['quantity'] ?? 0),
                        ];
                    }
                }
            }

            foreach ($newMaterials as &$newElem) {
                foreach ($newElem['materials'] as &$newMat) {
                    $key = $this->budgetMaterialKey($newElem, $newMat);
                    $legacyKey = $this->legacyBudgetMaterialKey($newElem, $newMat);
                    $match = $pricedMap[$key] ?? $pricedMap[$legacyKey] ?? null;

                    if ($match) {
                        $newMat['unitPrice'] = $match['unitPrice'];
                        $newMat['totalPrice'] = $newMat['quantity'] * $newMat['unitPrice'];
                        $newMat['_priceStatus'] = 'preserved';

                        if ((float) ($match['quantity'] ?? 0) !== (float) ($newMat['quantity'] ?? 0)) {
                            $newMat['_quantityChanged'] = true;
                            $newMat['_oldQuantity'] = $match['quantity'];
                        }

                        if (empty($newMat['library_material_id']) && !empty($match['library_material_id'])) {
                            $newMat['library_material_id'] = $match['library_material_id'];
                        }
                    }
                }
            }
        }

        // Initialize project info if first time
        $projectInfo = $budgetData->project_info ?? [
            'projectId' => $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? '',
            'enquiryTitle' => $task->enquiry->title ?? '',
            'clientName' => $task->enquiry->client->full_name ?? '',
            'eventVenue' => $task->enquiry->venue ?? '',
            'setupDate' => $task->enquiry->expected_delivery_date ?? ''
        ];

        // Recalculate summary with the new materials
        $summary = $this->calculateSummary([
            'materials' => $newMaterials,
            'labour' => $budgetData->labour_data ?? [],
            'expenses' => $budgetData->expenses_data ?? [],
            'logistics' => $budgetData->logistics_data ?? []
        ]);

        $metadata = $this->buildMaterialsImportMetadata($materialsTask, $materialsData, $newMaterials);

        $budgetData = DB::transaction(function () use ($taskId, $projectInfo, $newMaterials, $summary, $materialsTask, $metadata) {
            return TaskBudgetData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $projectInfo,
                    'materials_data' => $newMaterials,
                    'budget_summary' => $summary,
                    'materials_imported_at' => now(),
                    'last_import_date' => now(),
                    'materials_imported_from_task' => $materialsTask->id,
                    'materials_import_metadata' => $metadata
                ]
            );
        });

        return [
            'budget' => $budgetData,
            'message' => $wasCompleted
                ? 'Approved materials list imported into internal budget. This budget was reopened for review.'
                : 'Approved materials list imported into internal budget',
            'reopened' => $wasCompleted,
        ];
    }

    /**
     * Check if materials task has updates compared to what was imported
     */
    public function checkMaterialsUpdate(int $taskId): array
    {
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        if (!$budgetData || !$budgetData->materials_imported_at) {
            return ['has_updates' => false, 'hasUpdate' => false, 'message' => 'Ready for initial import'];
        }

        $task = EnquiryTask::find($taskId);
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) return ['has_updates' => false, 'hasUpdate' => false, 'message' => 'Source not found'];

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();
        if (!$materialsData) return ['has_updates' => false, 'hasUpdate' => false, 'message' => 'Approved materials data not found'];

        $lastUpdate = $materialsData->updated_at;
        $importDate = $budgetData->materials_imported_at;

        $hasUpdates = $lastUpdate->isAfter($importDate);

        // Always provide analysis so frontend can calculate baseline totals
        $analysis = $this->getSyncAnalysis($taskId);

        return [
            'has_updates' => $hasUpdates,
            'hasUpdate' => $hasUpdates,
            'last_import_at' => $importDate->toDateTimeString(),
            'materials_updated_at' => $lastUpdate->toDateTimeString(),
            'materials_task_title' => $materialsTask->title,
            'materials_import_metadata' => $budgetData->materials_import_metadata,
            'analysis' => $analysis,
            'message' => $hasUpdates ? 'Approved materials list has been updated' : 'Budget is in sync with approved materials'
        ];
    }

    /**
     * Get detailed comparison between current approved materials task and budget
     */
    public function getSyncAnalysis(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();
        
        if (!$materialsTask) {
            return ['hasUpdate' => false, 'message' => 'No materials task found'];
        }

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->with('elements.materials')->first();
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();

        if (!$materialsData || !$budgetData) {
            return ['hasUpdate' => false, 'message' => 'Missing data for comparison'];
        }

        try {
            $this->ensureMaterialsApproved($materialsData);
        } catch (\Exception $e) {
            return ['hasUpdate' => false, 'message' => $e->getMessage()];
        }

        $incomingMaterials = $this->transformMaterialsToBudget($materialsData);
        $existingMaterials = $budgetData->materials_data ?? [];
        $this->applyExistingBudgetPrices($existingMaterials, $incomingMaterials);

        $analysisData = $this->analyzeMaterialVariances($existingMaterials, $incomingMaterials);

        return [
            'hasUpdate' => !empty($analysisData['variances']),
            'summary' => [
                'added' => count(array_filter($analysisData['variances'], fn($v) => !empty($v['isNew']))),
                'updated' => count(array_filter($analysisData['variances'], fn($v) => empty($v['isNew']) && empty($v['isRemoved']))),
                'removed' => count(array_filter($analysisData['variances'], fn($v) => !empty($v['isRemoved']))),
                'no_change' => 0
            ],
            'details' => $analysisData['variances'],
            'analysis_raw_materials' => $incomingMaterials,
            'obsolete_persistent_ids' => array_map(fn($v) => $v['comparisonKey'], array_filter($analysisData['variances'], fn($v) => !empty($v['isRemoved'])))
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

    private function ensureMaterialsApproved(TaskMaterialsData $materialsData): void
    {
        $approvalStatus = $materialsData->project_info['approval_status'] ?? null;
        if (!empty($approvalStatus['all_approved'])) {
            return;
        }

        $missingApprovals = [];
        if (empty($approvalStatus['project_officer']['approved'])) {
            $missingApprovals[] = 'Project Officer';
        }
        if (empty($approvalStatus['production']['approved'])) {
            $missingApprovals[] = 'Production';
        }

        if (count($missingApprovals) === 2) {
            throw new \Exception('Cannot import materials to budget: BOTH Project Officer AND Production approvals are required. Currently missing both approvals.');
        }

        if (!empty($missingApprovals)) {
            throw new \Exception('Cannot import materials to budget: Missing approval from ' . $missingApprovals[0] . '. BOTH Project Officer AND Production approvals are mandatory.');
        }

        throw new \Exception('Cannot import materials to budget: Materials must be approved by BOTH Project Officer AND Production departments.');
    }

    private function buildMaterialsImportMetadata(EnquiryTask $materialsTask, TaskMaterialsData $materialsData, array $newMaterials): array
    {
        $latestVersion = $materialsData->versions()->orderByDesc('version_number')->first();
        $projectInfo = $materialsData->project_info ?? [];

        return [
            'source' => 'approved_materials_list',
            'imported_at' => now()->toISOString(),
            'materials_task_id' => $materialsTask->id,
            'materials_task_title' => $materialsTask->title,
            'materials_data_id' => $materialsData->id,
            'materials_updated_at' => optional($materialsData->updated_at)->toISOString(),
            'materials_version_id' => $latestVersion?->id,
            'materials_version_number' => $latestVersion?->version_number,
            'materials_version_label' => $latestVersion?->label,
            'quote_imported_from' => $projectInfo['quoteImportedFrom'] ?? null,
            'total_elements' => count($newMaterials),
            'total_materials' => array_sum(array_map(fn($element) => count($element['materials'] ?? []), $newMaterials)),
        ];
    }

    private function budgetMaterialKey(array $element, array $material): string
    {
        if (!empty($material['persistent_id'])) {
            return 'material:' . $material['persistent_id'];
        }

        return $this->legacyBudgetMaterialKey($element, $material);
    }

    private function legacyBudgetMaterialKey(array $element, array $material): string
    {
        $elementName = strtolower(preg_replace('/\s+/', '', (string) ($element['name'] ?? '')));
        $materialDescription = strtolower(preg_replace('/\s+/', '', (string) ($material['description'] ?? '')));

        return "legacy_{$elementName}_{$materialDescription}";
    }

    private function applyExistingBudgetPrices(array $existingMaterials, array &$incomingMaterials): void
    {
        $pricingMap = [];

        foreach ($existingMaterials as $element) {
            foreach ($element['materials'] ?? [] as $material) {
                $key = $this->budgetMaterialKey($element, $material);
                $pricingMap[$key] = [
                    'unitPrice' => (float) ($material['unitPrice'] ?? 0),
                    'quantity' => (float) ($material['quantity'] ?? 0),
                ];
            }
        }

        foreach ($incomingMaterials as &$element) {
            foreach ($element['materials'] as &$material) {
                $key = $this->budgetMaterialKey($element, $material);
                $legacyKey = $this->legacyBudgetMaterialKey($element, $material);
                $match = $pricingMap[$key] ?? $pricingMap[$legacyKey] ?? null;

                if (!$match) {
                    continue;
                }

                $material['unitPrice'] = $match['unitPrice'];
                $material['totalPrice'] = (float) ($material['quantity'] ?? 0) * $material['unitPrice'];

                if ((float) ($match['quantity'] ?? 0) !== (float) ($material['quantity'] ?? 0)) {
                    $material['_quantityChanged'] = true;
                    $material['_oldQuantity'] = $match['quantity'];
                }
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
                
                $budgetMaterials[] = [
                    'id' => $material->persistent_id ?: uniqid('mat_'),
                    'persistent_id' => $material->persistent_id,
                    'library_material_id' => $material->library_material_id,
                    'description' => $material->description,
                    'unitOfMeasurement' => $material->unit_of_measurement,
                    'quantity' => $material->quantity,
                    'unitPrice' => 0,
                    'totalPrice' => 0,
                    'category' => $element->category,
                    'is_included' => true,
                    '_priceStatus' => 'missing'
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
