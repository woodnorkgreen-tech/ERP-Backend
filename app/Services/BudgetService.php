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
            $budgetData = TaskBudgetData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $data['projectInfo'],
                    'materials_data' => $data['materials'] ?? [],
                    'labour_data' => $data['labour'] ?? [],
                    'expenses_data' => $data['expenses'] ?? [],
                    'logistics_data' => $data['logistics'] ?? [],
                    'budget_summary' => $this->calculateSummary($data),
                    'status' => $data['status'] ?? 'draft'
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
        $task = EnquiryTask::findOrFail($taskId);
        
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

        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        
        // If already imported and not forced, we might want to merge or skip
        // For simplicity now, we overwrite but preserve existing price if it matches
        $existingMaterials = $budgetData ? ($budgetData->materials_data ?? []) : [];
        $newMaterials = $this->transformMaterialsToBudget($materialsData);

        // Preserve logic (only if not forced)
        if (!$force) {
            $pricedMap = [];
            foreach ($existingMaterials as $elem) {
                foreach ($elem['materials'] ?? [] as $mat) {
                    if (($mat['unitPrice'] ?? 0) > 0) {
                        $pricedMap[$mat['description']] = $mat['unitPrice'];
                    }
                }
            }

            foreach ($newMaterials as &$newElem) {
                foreach ($newElem['materials'] as &$newMat) {
                    if (isset($pricedMap[$newMat['description']])) {
                        $newMat['unitPrice'] = $pricedMap[$newMat['description']];
                        $newMat['totalPrice'] = $newMat['quantity'] * $newMat['unitPrice'];
                        $newMat['_priceStatus'] = 'preserved';
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

        $budgetData = TaskBudgetData::updateOrCreate(
            ['enquiry_task_id' => $taskId],
            [
                'project_info' => $projectInfo,
                'materials_data' => $newMaterials,
                'budget_summary' => $summary,
                'materials_imported_at' => now(),
                'materials_imported_from_task' => $materialsTask->id,
                'materials_import_metadata' => [
                    'materials_task_title' => $materialsTask->title,
                    'total_elements' => count($newMaterials),
                    'total_materials' => array_sum(array_map(fn($e) => count($e['materials']), $newMaterials))
                ]
            ]
        );

        return [
            'budget' => $budgetData,
            'message' => 'Materials successfully imported from project HQ'
        ];
    }

    /**
     * Check if materials task has updates compared to what was imported
     */
    public function checkMaterialsUpdate(int $taskId): array
    {
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        if (!$budgetData || !$budgetData->materials_imported_at) {
            return ['has_updates' => false, 'message' => 'Ready for initial import'];
        }

        $task = EnquiryTask::find($taskId);
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();

        if (!$materialsTask) return ['has_updates' => false, 'message' => 'Source not found'];

        $lastUpdate = $materialsTask->updated_at;
        $importDate = $budgetData->materials_imported_at;

        $hasUpdates = $lastUpdate->isAfter($importDate);

        // Always provide analysis so frontend can calculate baseline totals
        $analysis = $this->getSyncAnalysis($taskId);

        return [
            'has_updates' => $hasUpdates,
            'last_import_at' => $importDate->toDateTimeString(),
            'materials_updated_at' => $lastUpdate->toDateTimeString(),
            'materials_task_title' => $materialsTask->title,
            'analysis' => $analysis,
            'message' => $hasUpdates ? 'Source project specifications have been updated' : 'Budget is in sync with project HQ'
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

        $incomingMaterials = $this->transformMaterialsToBudget($materialsData);
        $existingMaterials = $budgetData->materials_data ?? [];

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
     * Get preview of materials task in budget format
     */
    public function getMaterialsPreview(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);
        $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->where('type', 'materials')
            ->first();
        
        if (!$materialsTask) {
            throw new \Exception('No materials task found for this project');
        }

        $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->with('elements.materials')->first();
        
        if (!$materialsData) {
            return [];
        }

        return $this->transformMaterialsToBudget($materialsData);
    }

    /**
     * Submit budget for approval
     */
    public function submitForApproval(int $taskId): array
    {
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        if (!$budgetData) throw new \Exception('Budget data not found');

        $budgetData->update(['status' => 'pending_approval']);
        
        return [
            'status' => 'pending_approval',
            'message' => 'Budget submitted for internal approval'
        ];
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
                    'id' => uniqid('mat_'),
                    'persistent_id' => $material->persistent_id,
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
                    'id' => uniqid('elem_'),
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

    /**
     * Generate standard variance analysis data for an audit report
     */
    public function generateAuditReportData(int $taskId, mixed $baselineId = null): array
    {
        $task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        $budgetData = TaskBudgetData::where('enquiry_task_id', $taskId)->first();
        
        if (!$budgetData) throw new \Exception('Active budget data not found');

        $baselineData = null;
        $baselineTitle = 'Initial Budget';

        if ($baselineId === 'materials' || $baselineId === '0' || $baselineId === 0) {
            $baselineTitle = 'Project Master MQ (Design Spec)';
            $preview = $this->getMaterialsPreview($taskId);
            $baselineData = ['materials' => $preview];
        } elseif ($baselineId) {
            $version = \App\Models\BudgetVersion::find($baselineId);
            if ($version) {
                $baselineData = $version->data;
                $baselineTitle = "Snapshot #{$version->version_number} (" . ($version->label ?? 'Archived') . ")";
            }
        } else {
            // Default: Latest snapshot
            $version = \App\Models\BudgetVersion::where('task_budget_data_id', $budgetData->id)
                ->orderBy('version_number', 'desc')
                ->first();
            if ($version) {
                $baselineData = $version->data;
                $baselineTitle = "Snapshot #{$version->version_number}";
            }
        }

        if (!$baselineData) {
            $baselineData = ['materials' => [], 'labour' => [], 'expenses' => [], 'logistics' => []];
        }

        // Logic sync: Calculate totals using the same rules as frontend
        $currSum = $this->calculateSummary([
            'materials' => $budgetData->materials_data ?? [],
            'labour' => $budgetData->labour_data ?? [],
            'expenses' => $budgetData->expenses_data ?? [],
            'logistics' => $budgetData->logistics_data ?? []
        ]);

        $baseMats = $baselineData['materials'] ?? $baselineData['materials_data'] ?? [];
        $currMats = $budgetData->materials_data ?? [];

        // If auditing against Master MQ, we use pro-forma baseline (Master Qty * Current Budget Price)
        if ($baselineId === 'materials' || $baselineId === '0' || $baselineId === 0) {
            $pricingMap = [];
            foreach ($currMats as $el) {
                foreach ($el['materials'] ?? [] as $m) {
                    if ($m['isIncluded'] ?? $m['is_included'] ?? true) {
                        $eName = strtolower(preg_replace('/\s+/', '', ($el['name'] ?? '')));
                        $mDesc = strtolower(preg_replace('/\s+/', '', ($m['description'] ?? '')));
                        $key = ($m['persistent_id'] ?? null) ?: "legacy_{$eName}_{$mDesc}";
                        $pricingMap[$key] = $m['unitPrice'] ?? 0;
                    }
                }
            }

            // Rewrite base materials to have current prices for audit comparison
            foreach ($baseMats as &$el) {
                foreach ($el['materials'] ?? [] as &$m) {
                    $eName = strtolower(preg_replace('/\s+/', '', ($el['name'] ?? '')));
                    $mDesc = strtolower(preg_replace('/\s+/', '', ($m['description'] ?? '')));
                    $key = ($m['persistent_id'] ?? null) ?: "legacy_{$eName}_{$mDesc}";
                    $m['unitPrice'] = $pricingMap[$key] ?? 0;
                }
            }
        }

        $matAnalysis = $this->analyzeMaterialVariances($baseMats, $currMats);
        $labAnalysis = $this->analyzeStandardVariances($baselineData['labour'] ?? [], $budgetData->labour_data ?? [], 'LAB');
        $expAnalysis = $this->analyzeStandardVariances($baselineData['expenses'] ?? [], $budgetData->expenses_data ?? [], 'EXP');
        $logAnalysis = $this->analyzeStandardVariances($baselineData['logistics'] ?? [], $budgetData->logistics_data ?? [], 'LOG');

        $totalVar = $matAnalysis['summary']['totalVariance'] + $labAnalysis['summary']['totalVariance'] + $expAnalysis['summary']['totalVariance'] + $logAnalysis['summary']['totalVariance'];

        return [
            'enquiry' => $task->enquiry,
            'budgetData' => $budgetData,
            'currentTotal' => $currSum['grandTotal'],
            'baselineTotal' => $currSum['grandTotal'] - $totalVar,
            'baselineInfo' => ['title' => $baselineTitle],
            'auditSummary' => [
                'totalVariance' => $totalVar,
                'volumeVariance' => $matAnalysis['summary']['volumeVariance'] + $labAnalysis['summary']['volumeVariance'] + $expAnalysis['summary']['volumeVariance'] + $logAnalysis['summary']['volumeVariance'],
                'priceVariance' => $matAnalysis['summary']['priceVariance'] + $labAnalysis['summary']['priceVariance'] + $expAnalysis['summary']['priceVariance'] + $logAnalysis['summary']['priceVariance']
            ],
            'variances' => array_merge($matAnalysis['variances'], $labAnalysis['variances'], $expAnalysis['variances'], $logAnalysis['variances'])
        ];
    }

    /**
     * Internal helper to generate variance analysis for standard flat lists
     */
    protected function analyzeStandardVariances(array $baseList, array $currList, string $suffix): array
    {
        $variances = [];
        $totalVariance = 0;
        $volVar = 0;
        $priceVar = 0;

        $getKey = function($item) {
            return strtolower(preg_replace('/\s+/', '', ($item['description'] ?? $item['type'] ?? 'item')));
        };

        $baseMap = [];
        foreach ($baseList as $item) {
            $baseMap[$getKey($item)] = $item;
        }

        $handledKeys = [];

        foreach ($currList as $item) {
            $key = $getKey($item);
            $handledKeys[] = $key;
            $matching = $baseMap[$key] ?? null;

            $Qb = $matching ? ($matching['quantity'] ?? $matching['units'] ?? 1) : 0;
            $Pb = $matching ? ($matching['unitRate'] ?? $matching['rate'] ?? $matching['amount'] ?? 0) : 0;
            $Qc = $item['quantity'] ?? $item['units'] ?? 1;
            $Pc = $item['unitRate'] ?? $item['rate'] ?? $item['amount'] ?? 0;

            $variance = ($Qc * $Pc) - ($Qb * $Pb);
            if (abs($variance) < 0.01) continue;

            $ivolVar = ($Qc - $Qb) * $Pb;
            $ipriceVar = ($Pc - $Pb) * $Qc;

            $variances[] = [
                'description' => "[{$suffix}] " . ($item['description'] ?? $item['type'] ?? 'N/A'),
                'comparisonKey' => $key,
                'baselineQty' => $Qb,
                'currentQty' => $Qc,
                'variance' => $variance,
                'volumeVariance' => $ivolVar,
                'priceVariance' => $ipriceVar,
                'isNew' => !$matching
            ];

            $totalVariance += $variance;
            $volVar += $ivolVar;
            $priceVar += $ipriceVar;
        }

        foreach ($baseMap as $key => $rem) {
            if (!in_array($key, $handledKeys)) {
                $q = ($rem['quantity'] ?? $rem['units'] ?? 1);
                $p = ($rem['unitRate'] ?? $rem['rate'] ?? $rem['amount'] ?? 0);
                $var = -($q * $p);
                
                $variances[] = [
                    'description' => "[{$suffix}] " . ($rem['description'] ?? $rem['type'] ?? 'N/A'),
                    'comparisonKey' => $key,
                    'baselineQty' => $q,
                    'currentQty' => 0,
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
}
