<?php

namespace App\Http\Controllers;

use App\Models\TaskQuoteData;
use App\Models\TaskBudgetData;
use App\Models\QuoteVersion;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;

class QuoteController extends Controller
{
    public function __construct()
    {
        // No permission restrictions for quote operations
    }

    /**
     * Get quote data for a task
     */
    public function getQuoteData(int $taskId): JsonResponse
    {
        try {
            $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

            if (!$quoteData) {
                return response()->json([
                    'data' => $this->getDefaultQuoteStructure($taskId),
                    'message' => 'Quote data retrieved successfully'
                ]);
            }

            // Load task with enquiry and client relationship
            $task = EnquiryTask::with('enquiry.client')->find($taskId);

            return response()->json([
                'data' => $this->formatQuoteResponse($quoteData, $task),
                'message' => 'Quote data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve quote data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save quote data for a task
     */
    public function saveQuoteData(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'projectInfo' => 'required|array',
            'budgetImported' => 'boolean',
            'materials' => 'present|array',
            'labour' => 'present|array',
            'expenses' => 'present|array',
            'logistics' => 'present|array',
            'margins' => 'required|array',
            'margins.materials' => 'numeric|min:0|max:100',
            'margins.labour' => 'numeric|min:0|max:100',
            'margins.expenses' => 'numeric|min:0|max:100',
            'margins.logistics' => 'numeric|min:0|max:100',
            'discountAmount' => 'numeric|min:0',
            'vatPercentage' => 'numeric|min:0|max:100',
            'vatEnabled' => 'boolean',
            'totals' => 'required|array',
            'status' => 'required|in:draft,pending,approved,rejected'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $quoteData = TaskQuoteData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $request->projectInfo,
                    'budget_imported' => $request->budgetImported ?? false,
                    'materials' => $request->materials,
                    'labour' => $request->labour,
                    'expenses' => $request->expenses,
                    'logistics' => $request->logistics,
                    'margins' => $request->margins,
                    'discount_amount' => $request->discountAmount ?? 0,
                    'vat_percentage' => $request->vatPercentage ?? 16,
                    'vat_enabled' => $request->vatEnabled ?? true,
                    'totals' => $request->totals,
                    'status' => $request->status,
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'data' => $this->formatQuoteResponse($quoteData->fresh()),
                'message' => 'Quote data saved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save quote data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import budget data into quote
      */
     public function importBudgetData(int $taskId): JsonResponse
     {
         try {
             \Log::info("Starting budget import for task ID: {$taskId}");

             $task = EnquiryTask::with('enquiry.client')->find($taskId);

             if (!$task) {
                 \Log::warning("Quote task not found: {$taskId}");
                 return response()->json([
                     'message' => 'Quote task not found'
                 ], 404);
             }

             \Log::info("Found quote task: {$taskId}, enquiry ID: {$task->project_enquiry_id}");

             // Find budget task for the same enquiry
             $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                 ->where('type', 'budget')
                 ->first();

             if (!$budgetTask) {
                 \Log::warning("Budget task not found for enquiry: {$task->project_enquiry_id}");
                 return response()->json([
                     'message' => 'Budget task not found for this enquiry'
                 ], 404);
             }

             \Log::info("Found budget task: {$budgetTask->id}, status: {$budgetTask->status}");

             // Check if budget task is completed
             // if ($budgetTask->status !== 'completed') {
             //     return response()->json([
             //         'message' => 'Budget task must be completed before importing data into quote'
             //     ], 409); // 409 Conflict - indicates the request conflicts with current state
             // }

             // Get budget data
             $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

             if (!$budgetData) {
                 \Log::warning("No budget data found for budget task: {$budgetTask->id}");
                 return response()->json([
                     'message' => 'No budget data found'
                 ], 404);
             }

             \Log::info("Found budget data, transforming to quote format");

             // Get approved budget additions for this budget
             $approvedAdditions = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)
                 ->where('status', 'approved')
                 ->get();

             // Transform budget data to quote format including approved additions
             $quoteData = $this->transformBudgetToQuote($budgetData, $approvedAdditions);

             \Log::info("Transformed budget data, creating/updating quote");

             // Create or update quote with imported data
             $quote = TaskQuoteData::updateOrCreate(
                 ['enquiry_task_id' => $taskId],
                 [
                     'project_info' => $budgetData->project_info,
                     'budget_imported' => true,
                     'budget_imported_at' => now(),
                     'budget_updated_at' => $budgetData->updated_at,
                     'budget_version' => 'v_' . $budgetData->id . '_' . $budgetData->updated_at->format('YmdHis'),
                     'custom_margins' => [], // Initialize empty
                     'materials' => $quoteData['materials'],
                     'labour' => $quoteData['labour'],
                     'expenses' => $quoteData['expenses'],
                     'logistics' => $quoteData['logistics'],
                     'margins' => [
                         'materials' => 60,
                         'labour' => 0,
                         'expenses' => 0,
                         'logistics' => 0
                     ],
                     'discount_amount' => 0,
                     'vat_percentage' => 16,
                     'vat_enabled' => true,
                     'totals' => $quoteData['totals'],
                     'status' => 'draft',
                     'updated_at' => now()
                 ]
             );

             \Log::info("Quote data imported successfully for task: {$taskId}");

             return response()->json([
                 'data' => $this->formatQuoteResponse($quote->fresh(), $task),
                 'message' => 'Budget data imported successfully'
             ]);

         } catch (\Exception $e) {
             \Log::error("Failed to import budget data for task {$taskId}: " . $e->getMessage());
             \Log::error("Stack trace: " . $e->getTraceAsString());
             return response()->json([
                 'message' => 'Failed to import budget data',
                 'error' => $e->getMessage()
             ], 500);
         }
     }

    /**
     * Check if budget data has been updated since quote import
     */
    public function checkBudgetStatus(int $taskId): JsonResponse
    {
        try {
            $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();
            
            if (!$quoteData || !$quoteData->budget_imported) {
                return response()->json([
                    'status' => 'no_budget',
                    'message' => 'No budget has been imported yet'
                ]);
            }

            $task = EnquiryTask::find($taskId);
            $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) {
                return response()->json(['status' => 'no_budget', 'message' => 'Budget task not found']);
            }

            $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            
            if (!$budgetData) {
                return response()->json(['status' => 'no_budget', 'message' => 'No budget data found']);
            }

            $budgetUpdatedAt = $budgetData->updated_at;
            $quoteImportedAt = $quoteData->budget_imported_at ?? $quoteData->created_at;
            
            $isOutdated = $budgetUpdatedAt->gt($quoteImportedAt);

            return response()->json([
                'status' => $isOutdated ? 'outdated' : 'up_to_date',
                'budget_updated_at' => $budgetUpdatedAt,
                'quote_imported_at' => $quoteImportedAt,
                'budget_version' => $quoteData->budget_version,
                'message' => $isOutdated 
                    ? 'Budget has been updated since quote was created' 
                    : 'Quote is up to date with budget'
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to check budget status for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to check budget status', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview changes from budget before applying
     */
    public function previewBudgetChanges(int $taskId): JsonResponse
    {
        try {
            $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();
            if (!$quoteData) {
                return response()->json(['message' => 'Quote not found'], 404);
            }

            $task = EnquiryTask::find($taskId);
            $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) {
                return response()->json(['message' => 'Budget task not found'], 404);
            }

            $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'No budget data found'], 404);
            }

            $approvedAdditions = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)
                ->where('status', 'approved')
                ->get();

            $newQuoteData = $this->transformBudgetToQuote($budgetData, $approvedAdditions);

            $changes = ['new_items' => [], 'price_changes' => [], 'removed_items' => [], 'total_impact' => []];

            $currentMaterialIds = collect($quoteData->materials)->pluck('id')->toArray();
            $newMaterialIds = collect($newQuoteData['materials'])->pluck('id')->toArray();

            foreach ($newQuoteData['materials'] as $newMaterial) {
                if (!in_array($newMaterial['id'], $currentMaterialIds)) {
                    $changes['new_items'][] = ['type' => 'material', 'name' => $newMaterial['name'], 'price' => $newMaterial['baseTotal']];
                }
            }

            foreach ($newQuoteData['materials'] as $newMaterial) {
                foreach ($quoteData->materials as $currentMaterial) {
                    if ($newMaterial['id'] === $currentMaterial['id'] && $newMaterial['baseTotal'] != $currentMaterial['baseTotal']) {
                        $changes['price_changes'][] = [
                            'type' => 'material',
                            'name' => $newMaterial['name'],
                            'old_price' => $currentMaterial['baseTotal'],
                            'new_price' => $newMaterial['baseTotal'],
                            'change_percent' => $currentMaterial['baseTotal'] > 0 
                                ? (($newMaterial['baseTotal'] - $currentMaterial['baseTotal']) / $currentMaterial['baseTotal'] * 100) : 0
                        ];
                    }
                }
            }

            foreach ($quoteData->materials as $currentMaterial) {
                if (!in_array($currentMaterial['id'], $newMaterialIds)) {
                    $changes['removed_items'][] = ['type' => 'material', 'name' => $currentMaterial['name'], 'price' => $currentMaterial['baseTotal']];
                }
            }

            $currentTotal = $quoteData->totals['grandTotal'] ?? 0;
            $newTotal = $newQuoteData['totals']['grandTotal'] ?? 0;
            
            $changes['total_impact'] = [
                'current_total' => $currentTotal,
                'new_total' => $newTotal,
                'difference' => $newTotal - $currentTotal,
                'change_percent' => $currentTotal > 0 ? (($newTotal - $currentTotal) / $currentTotal * 100) : 0
            ];

            return response()->json(['data' => $changes, 'message' => 'Change preview generated successfully']);

        } catch (\Exception $e) {
            \Log::error("Failed to preview budget changes for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to preview budget changes', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Smart merge budget data - preserves custom margins
     */
    public function smartMergeBudget(int $taskId): JsonResponse
    {
        try {
            $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();
            if (!$quoteData) {
                return response()->json(['message' => 'Quote not found'], 404);
            }

            $task = EnquiryTask::find($taskId);
            $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)->where('type', 'budget')->first();
            if (!$budgetTask) {
                return response()->json(['message' => 'Budget task not found'], 404);
            }

            $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();
            if (!$budgetData) {
                return response()->json(['message' => 'No budget data found'], 404);
            }

            // Get fresh data from budget
            $approvedAdditions = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)->where('status', 'approved')->get();
            $newQuoteData = $this->transformBudgetToQuote($budgetData, $approvedAdditions);

            // MERGE LOGIC:
            // We want to keep the NEW budget structure (items, quantities, costs)
            // But preserve the OLD quote customizations (margins, days)
            
            $existingMaterials = $quoteData->materials ?? [];
            $mergedMaterials = $this->mergeQuoteMaterials($existingMaterials, $newQuoteData['materials']);
            
            // Merge items categories to preserve margins
            $mergedLabour = $this->mergeSimpleItems($quoteData->labour ?? [], $newQuoteData['labour']);
            $mergedExpenses = $this->mergeSimpleItems($quoteData->expenses ?? [], $newQuoteData['expenses']);
            $mergedLogistics = $this->mergeSimpleItems($quoteData->logistics ?? [], $newQuoteData['logistics']);
            
            // Preserve global margins if they exist, otherwise use defaults
            $margins = $quoteData->margins ?? [];
            $margins['materials'] = $margins['materials'] ?? 60;
            $margins['labour'] = $margins['labour'] ?? 0;
            $margins['expenses'] = $margins['expenses'] ?? 0;
            $margins['logistics'] = $margins['logistics'] ?? 0;

            // Update newQuoteData with merged items
            $newQuoteData['materials'] = $mergedMaterials;
            $newQuoteData['labour'] = $mergedLabour;
            $newQuoteData['expenses'] = $mergedExpenses;
            $newQuoteData['logistics'] = $mergedLogistics;

            $totals = $this->recalculateTotals($newQuoteData, $margins);

            $quoteData->update([
                'materials' => $mergedMaterials,
                'labour' => $mergedLabour, 
                'expenses' => $mergedExpenses,
                'logistics' => $mergedLogistics,
                'totals' => $totals,
                'margins' => $margins, 
                'budget_imported_at' => now(),
                'budget_updated_at' => $budgetData->updated_at,
                'budget_version' => 'v_' . $budgetData->id . '_' . $budgetData->updated_at->format('YmdHis'),
                 'updated_at' => now()
            ]);

            \Log::info("Smart merge completed for task {$taskId}");
            return response()->json([
                'data' => $this->formatQuoteResponse($quoteData->fresh(), $task), 
                'message' => 'Budget data merged successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to smart merge budget for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Failed to merge budget data', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Merge existing material customization into new budget structure
     */
    private function mergeQuoteMaterials(array $existingMaterials, array $newMaterials): array
    {
        $existingIdMap = [];
        $existingDescMap = [];
        
        // Map existing items by ID and by normalized Description
        foreach ($existingMaterials as $element) {
            foreach ($element['materials'] ?? [] as $material) {
                if (!empty($material['id'])) {
                    $existingIdMap[(string)$material['id']] = $material;
                }
                $descKey = trim($material['description'] ?? '');
                if (!empty($descKey)) {
                    $existingDescMap[$descKey] = $material;
                }
            }
        }

        // 1. Process New Materials from Budget
        foreach ($newMaterials as &$element) {
            foreach ($element['materials'] as &$material) {
                $match = null;
                $mid = (string)($material['id'] ?? '');
                $mdesc = trim($material['description'] ?? '');

                // Try to find a match in the existing Quote
                if (!empty($mid) && isset($existingIdMap[$mid])) {
                    $match = $existingIdMap[$mid];
                } elseif (!empty($mdesc) && isset($existingDescMap[$mdesc])) {
                    $match = $existingDescMap[$mdesc];
                }

                if ($match) {
                    // PRESERVE CUSTOMIZATIONS
                    $material['marginPercentage'] = $match['marginPercentage'] ?? 60;
                    $material['days'] = $match['days'] ?? 1;
                    
                    if (!empty($match['description'])) {
                        $material['description'] = $match['description'];
                    }
                    
                    // Recalculate
                    $material['totalPrice'] = $material['quantity'] * $material['unitPrice'] * ($material['days'] ?? 1);
                    $material['marginAmount'] = $material['totalPrice'] * ($material['marginPercentage'] / 100);
                    $material['finalPrice'] = $material['totalPrice'] + $material['marginAmount'];
                    
                    // Remove from maps to avoid duplication
                    if (!empty($match['id'])) unset($existingIdMap[(string)$match['id']]);
                    unset($existingDescMap[trim($match['description'] ?? '')]);
                }
            }
            
            // Re-sum element totals
            $element['baseTotal'] = array_sum(array_column($element['materials'], 'totalPrice'));
            $element['marginAmount'] = array_sum(array_column($element['materials'], 'marginAmount'));
            $element['finalTotal'] = array_sum(array_column($element['materials'], 'finalPrice'));
            $element['marginPercentage'] = $element['baseTotal'] > 0 ? ($element['marginAmount'] / $element['baseTotal'] * 100) : 60;
        }

        // 2. Add back items that ONLY exist in the Quote (Manual additions or unique edits)
        // We only add orphans that haven't been matched by ID OR Description
        foreach ($existingIdMap as $orphan) {
            $orphanDesc = trim($orphan['description'] ?? '');
            if (isset($existingDescMap[$orphanDesc])) {
                // Find element and add back
                foreach ($newMaterials as &$newElement) {
                    if ($newElement['id'] === ($orphan['element_id'] ?? null)) {
                        $newElement['materials'][] = $orphan;
                        unset($existingDescMap[$orphanDesc]);
                        continue 2;
                    }
                }
                // Fallback: Add to first element or keep as-is if element mapping fails
                if (!empty($newMaterials)) {
                    $newMaterials[0]['materials'][] = $orphan;
                }
            }
        }

        return $newMaterials;
    }

    /**
     * Merge simple items (Expenses, Logistics) to preserve margins
     */
    private function mergeSimpleItems(array $existingItems, array $newItems): array
    {
        $existingIdMap = [];
        $existingDescMap = [];

        foreach ($existingItems as $item) {
            if (!empty($item['id'])) {
                $existingIdMap[(string)$item['id']] = $item;
            }
            $descKey = trim($item['description'] ?? $item['type'] ?? '');
            if (!empty($descKey)) {
                $existingDescMap[$descKey] = $item;
            }
        }

        foreach ($newItems as &$item) {
            $match = null;
            $iid = (string)($item['id'] ?? '');
            $idesc = trim($item['description'] ?? $item['type'] ?? '');

            if (!empty($iid) && isset($existingIdMap[$iid])) {
                $match = $existingIdMap[$iid];
            } elseif (!empty($idesc) && isset($existingDescMap[$idesc])) {
                $match = $existingDescMap[$idesc];
            }

            if ($match) {
                $item['marginPercentage'] = $match['marginPercentage'] ?? 0;
                
                if (!empty($match['type'])) $item['type'] = $match['type'];
                if (!empty($match['description'])) $item['description'] = $match['description'];
                
                $baseAmount = $item['amount'] ?? 0;
                $item['marginAmount'] = $baseAmount * ($item['marginPercentage'] / 100);
                $item['finalPrice'] = $baseAmount + $item['marginAmount'];
                
                if (!empty($match['id'])) unset($existingIdMap[(string)$match['id']]);
                unset($existingDescMap[trim($match['description'] ?? $match['type'] ?? '')]);
            }
        }

        // Add back genuine orphans (manually added items that don't exist in budget)
        foreach ($existingIdMap as $orphan) {
            $orphanDesc = trim($orphan['description'] ?? $orphan['type'] ?? '');
            if (isset($existingDescMap[$orphanDesc])) {
                $newItems[] = $orphan;
            }
        }

        return $newItems;
    }

    /**
     * Recalculate totals with current margins
     */
    private function recalculateTotals(array $quoteData, array $margins): array
    {
        $materialsBase = array_sum(array_column($quoteData['materials'], 'baseTotal'));
        $materialsMargin = array_sum(array_column($quoteData['materials'], 'marginAmount'));
        $materialsTotal = array_sum(array_column($quoteData['materials'], 'finalTotal'));

        $labourBase = array_sum(array_column($quoteData['labour'], 'amount'));
        $labourMargin = $labourBase * ($margins['labour'] / 100);
        $labourTotal = $labourBase + $labourMargin;

        $expensesBase = array_sum(array_column($quoteData['expenses'], 'amount'));
        $expensesMargin = array_sum(array_column($quoteData['expenses'], 'marginAmount'));
        $expensesTotal = array_sum(array_column($quoteData['expenses'], 'finalPrice'));

        $logisticsBase = array_sum(array_column($quoteData['logistics'], 'amount'));
        $logisticsMargin = array_sum(array_column($quoteData['logistics'], 'marginAmount'));
        $logisticsTotal = array_sum(array_column($quoteData['logistics'], 'finalPrice'));

        $subtotal = $materialsTotal + $labourTotal + $expensesTotal + $logisticsTotal;
        $totalAfterDiscount = $subtotal;
        $vatAmount = $totalAfterDiscount * 0.16;
        $grandTotal = $totalAfterDiscount + $vatAmount;

        return [
            'materialsBase' => round($materialsBase, 2),
            'materialsMargin' => round($materialsMargin, 2),
            'materialsTotal' => round($materialsTotal, 2),
            'labourBase' => round($labourBase, 2),
            'labourMargin' => round($labourMargin, 2),
            'labourTotal' => round($labourTotal, 2),
            'expensesBase' => round($expensesBase, 2),
            'expensesMargin' => round($expensesMargin, 2),
            'expensesTotal' => round($expensesTotal, 2),
            'logisticsBase' => round($logisticsBase, 2),
            'logisticsMargin' => round($logisticsMargin, 2),
            'logisticsTotal' => round($logisticsTotal, 2),
            'subtotal' => round($subtotal, 2),
            'discountAmount' => 0,
            'totalAfterDiscount' => round($totalAfterDiscount, 2),
            'vatPercentage' => 16,
            'vatAmount' => round($vatAmount, 2),
            'grandTotal' => round($grandTotal, 2),
            'totalMargin' => round($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin, 2),
            'overallMarginPercentage' => $subtotal > 0 ? round(($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin) / ($materialsBase + $labourBase + $expensesBase + $logisticsBase) * 100, 2) : 0
        ];
    }

    /**
     * Get unit price for a material from budget data or approved additions
     */
    private function getBudgetMaterialPrice(TaskBudgetData $budgetData, $materialId): float
    {
        \Log::info("getBudgetMaterialPrice called", [
            'materialId' => $materialId,
            'budgetDataId' => $budgetData->id
        ]);

        // First check the main budget materials data
        if ($budgetData->materials_data) {
            foreach ($budgetData->materials_data as $element) {
                foreach ($element['materials'] ?? [] as $material) {
                    if (isset($material['id']) && $material['id'] == $materialId) {
                        \Log::info("Found material in main budget data", [
                            'materialId' => $materialId,
                            'unitPrice' => $material['unitPrice'] ?? 0
                        ]);
                        return (float) ($material['unitPrice'] ?? 0);
                    }
                }
            }
        }

        // If not found in main budget, check ALL budget additions (approved and draft)
        // We check draft too because the user might have updated the price but not approved yet
        $additions = \App\Models\BudgetAddition::where('task_budget_data_id', $budgetData->id)
            ->where('source_type', 'materials_additional')
            ->whereIn('status', ['approved', 'draft']) // Include draft to get latest prices
            ->orderBy('updated_at', 'desc') // Get most recent first
            ->get();

        \Log::info("Checking budget additions for material price", [
            'materialId' => $materialId,
            'additionsCount' => $additions->count()
        ]);

        foreach ($additions as $addition) {
            // Check if this addition is for the material we're looking for
            if ($addition->source_material_id == $materialId) {
                if ($addition->materials) {
                    foreach ($addition->materials as $material) {
                        // Prioritize non-zero values, check both camelCase and snake_case
                        $unitPrice = ($material['unitPrice'] ?? 0) > 0
                            ? (float) $material['unitPrice']
                            : (float) ($material['unit_price'] ?? 0);

                        \Log::info("Checking material in addition", [
                            'additionId' => $addition->id,
                            'additionStatus' => $addition->status,
                            'materialId' => $materialId,
                            'material_unitPrice' => $material['unitPrice'] ?? null,
                            'material_unit_price' => $material['unit_price'] ?? null,
                            'calculated_unitPrice' => $unitPrice
                        ]);

                        if ($unitPrice > 0) {
                            \Log::info("Found material price in budget addition", [
                                'additionId' => $addition->id,
                                'additionStatus' => $addition->status,
                                'materialId' => $materialId,
                                'unitPrice' => $unitPrice
                            ]);
                            return $unitPrice;
                        }
                    }
                }
            }
        }

        \Log::warning("No unit price found for material", [
            'materialId' => $materialId
        ]);

        return 0.0;
    }

    /**
     * Transform budget data to quote format
     */
    private function transformBudgetToQuote(TaskBudgetData $budgetData, $approvedAdditions = null): array
    {
        \Log::info("Starting budget data transformation");

        $materials = [];
        $labour = [];
        $expenses = [];
        $logistics = [];

        // Transform materials with margin structure
        if ($budgetData->materials_data) {
            \Log::info("Transforming materials data: " . count($budgetData->materials_data) . " elements");
            foreach ($budgetData->materials_data as $element) {
                $elementMaterials = [];
                foreach ($element['materials'] as $material) {
                    $elementMaterials[] = [
                        'id' => $material['id'],
                        'description' => $material['description'],
                        'unitOfMeasurement' => $material['unitOfMeasurement'] ?? $material['unit_of_measurement'] ?? '',
                        'quantity' => $material['quantity'],
                        'days' => 1, // Default days
                        'unitPrice' => $material['unitPrice'] ?? 0,
                        'totalPrice' => $material['totalPrice'] ?? 0,
                        'isAddition' => $material['isAddition'] ?? false,
                        'marginPercentage' => 60, // Default margin
                        'marginAmount' => ($material['totalPrice'] ?? 0) * 0.6,
                        'finalPrice' => ($material['totalPrice'] ?? 0) * 1.6
                    ];
                }

                $materials[] = [
                    'id' => $element['id'],
                    'templateId' => $element['templateId'] ?? null,
                    'name' => $element['name'],
                    'materials' => $elementMaterials,
                    'baseTotal' => array_sum(array_column($elementMaterials, 'totalPrice')),
                    'marginPercentage' => 60,
                    'marginAmount' => array_sum(array_column($elementMaterials, 'marginAmount')),
                    'finalTotal' => array_sum(array_column($elementMaterials, 'finalPrice'))
                ];
            }
        }

        // Add approved budget additions to materials, labour, expenses, logistics
        if ($approvedAdditions) {
            \Log::info("Including " . $approvedAdditions->count() . " approved budget additions");

            foreach ($approvedAdditions as $addition) {
                // Add materials from approved additions
                if ($addition->materials) {
                    foreach ($addition->materials as $material) {
                        // Calculate totals for this material
                        // Support both camelCase and snake_case field names
                        // Prioritize non-zero values
                        $quantity = $material['quantity'] ?? 0;
                        $unitPrice = ($material['unitPrice'] ?? 0) > 0
                            ? $material['unitPrice']
                            : ($material['unit_price'] ?? 0);
                        $totalPrice = ($material['totalPrice'] ?? 0) > 0
                            ? $material['totalPrice']
                            : (($material['total_price'] ?? 0) > 0
                                ? $material['total_price']
                                : ($quantity * $unitPrice));

                        \Log::info("Processing addition material", [
                            'addition_id' => $addition->id,
                            'addition_status' => $addition->status,
                            'material_id' => $material['id'] ?? null,
                            'quantity' => $quantity,
                            'unitPrice' => $unitPrice,
                            'totalPrice' => $totalPrice,
                            'source_type' => $addition->source_type,
                            'source_material_id' => $addition->source_material_id,
                            'material_data' => $material
                        ]);

                        // For materials_additional type, always get the latest price from the addition itself
                        // (which was updated when the user edited the virtual addition)
                        if ($addition->source_type === 'materials_additional' && $addition->source_material_id) {
                            \Log::info("Getting latest unit price for materials_additional type", [
                                'addition_id' => $addition->id,
                                'source_material_id' => $addition->source_material_id,
                                'current_unitPrice' => $unitPrice
                            ]);

                            // Get the latest price from budget additions (including draft updates)
                            $latestPrice = $this->getBudgetMaterialPrice($budgetData, $addition->source_material_id);
                            if ($latestPrice > 0) {
                                \Log::info("Using latest unit price from budget addition", [
                                    'source_material_id' => $addition->source_material_id,
                                    'latest_price' => $latestPrice,
                                    'original_price' => $unitPrice
                                ]);
                                $unitPrice = $latestPrice;
                            } else {
                                \Log::info("No updated price found, using addition's stored price", [
                                    'source_material_id' => $addition->source_material_id,
                                    'stored_price' => $unitPrice
                                ]);
                            }
                        }

                        // Recalculate totals with final unit price
                        $totalPrice = $quantity * $unitPrice;
                        $marginAmount = $totalPrice * 0.6; // 60% margin
                        $finalPrice = $totalPrice + $marginAmount;

                        \Log::info("Final addition material calculations", [
                            'addition_id' => $addition->id,
                            'material_id' => $material['id'] ?? null,
                            'final_unitPrice' => $unitPrice,
                            'final_totalPrice' => $totalPrice,
                            'final_marginAmount' => $marginAmount,
                            'final_finalPrice' => $finalPrice
                        ]);

                        $materials[] = [
                            'id' => 'addition_' . $addition->id . '_material_' . $material['id'],
                            'templateId' => null,
                            'name' => 'Budget Addition: ' . $addition->title,
                            'materials' => [
                                [
                                    'id' => $material['id'],
                                    'description' => $material['description'],
                                    'unitOfMeasurement' => $material['unitOfMeasurement'] ?? $material['unit_of_measurement'] ?? '',
                                    'quantity' => $quantity,
                                    'days' => 1,
                                    'unitPrice' => $unitPrice,
                                    'totalPrice' => $totalPrice,
                                    'isAddition' => true,
                                    'marginPercentage' => 60,
                                    'marginAmount' => $marginAmount,
                                    'finalPrice' => $finalPrice
                                ]
                            ],
                            'baseTotal' => $totalPrice,
                            'marginPercentage' => 60,
                            'marginAmount' => $marginAmount,
                            'finalTotal' => $finalPrice
                        ];
                    }
                }

                // Add labour from approved additions
                if ($addition->labour) {
                    $labour = array_merge($labour, array_map(function ($item) {
                        $amount = $item['amount'] ?? (($item['quantity'] ?? 1) * ($item['unitRate'] ?? 0));
                        return [
                            'id' => $item['id'],
                            'type' => $item['type'] ?? 'Labour',
                            'unit' => $item['unit'] ?? 'Hours',
                            'quantity' => $item['quantity'] ?? 1,
                            'unitRate' => $item['unitRate'] ?? $amount,
                            'amount' => $amount,
                            'isAddition' => true,
                            'category' => $item['category'] ?? 'Additional Labour',
                            'marginPercentage' => 0,
                            'marginAmount' => 0,
                            'finalPrice' => $amount
                        ];
                    }, $addition->labour));
                }

                // Add expenses from approved additions
                if ($addition->expenses) {
                    $expenses = array_merge($expenses, array_map(function ($item) {
                        $amount = $item['amount'] ?? 0;
                        $marginAmount = 0; // 0% margin
                        $finalPrice = $amount + $marginAmount;
                        return [
                            'id' => $item['id'],
                            'description' => $item['description'],
                            'category' => $item['category'] ?? 'Additional Expense',
                            'amount' => $amount,
                            'isAddition' => true,
                            'marginPercentage' => 0,
                            'marginAmount' => $marginAmount,
                            'finalPrice' => $finalPrice
                        ];
                    }, $addition->expenses));
                }

                // Add logistics from approved additions
                if ($addition->logistics) {
                    $logistics = array_merge($logistics, array_map(function ($item) {
                        $amount = $item['amount'] ?? (($item['quantity'] ?? 1) * ($item['unitRate'] ?? 0));
                        $marginAmount = 0; // 0% margin
                        $finalPrice = $amount + $marginAmount;
                        return [
                            'id' => $item['id'],
                            'vehicleReg' => $item['vehicleReg'] ?? '',
                            'description' => $item['description'],
                            'unit' => $item['unit'] ?? 'Trip',
                            'quantity' => $item['quantity'] ?? 1,
                            'unitRate' => $item['unitRate'] ?? $amount,
                            'amount' => $amount,
                            'isAddition' => true,
                            'marginPercentage' => 0,
                            'marginAmount' => $marginAmount,
                            'finalPrice' => $finalPrice
                        ];
                    }, $addition->logistics));
                }
            }
        }

        // Transform labour (no individual margins)
        if ($budgetData->labour_data) {
            \Log::info("Transforming labour data: " . count($budgetData->labour_data) . " items");
            $labour = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unitRate' => $item['unitRate'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'category' => $item['category'] ?? 'General',
                    'marginPercentage' => 0,
                    'marginAmount' => 0,
                    'finalPrice' => $item['amount']
                ];
            }, $budgetData->labour_data);
        }

        // Transform expenses with margins
        if ($budgetData->expenses_data) {
            \Log::info("Transforming expenses data: " . count($budgetData->expenses_data) . " items");
            $expenses = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'description' => $item['description'],
                    'category' => $item['category'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'marginPercentage' => 0,
                    'marginAmount' => 0,
                    'finalPrice' => $item['amount']
                ];
            }, $budgetData->expenses_data);
        }

        // Transform logistics with margins
        if ($budgetData->logistics_data) {
            \Log::info("Transforming logistics data: " . count($budgetData->logistics_data) . " items");
            $logistics = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'vehicleReg' => $item['vehicleReg'] ?? '',
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unitRate' => $item['unitRate'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'marginPercentage' => 0,
                    'marginAmount' => 0,
                    'finalPrice' => $item['amount']
                ];
            }, $budgetData->logistics_data);
        }

        // Calculate totals
        \Log::info("Calculating totals for transformed data");

        // Debug: Log materials data to check baseTotal values
        \Log::info("Materials data for totals calculation:", array_map(function($mat) {
            return [
                'name' => $mat['name'],
                'baseTotal' => $mat['baseTotal'],
                'marginAmount' => $mat['marginAmount'],
                'finalTotal' => $mat['finalTotal']
            ];
        }, $materials));

        $materialsBase = array_sum(array_column($materials, 'baseTotal'));
        $materialsMargin = array_sum(array_column($materials, 'marginAmount'));
        $materialsTotal = array_sum(array_column($materials, 'finalTotal'));

        $labourBase = array_sum(array_column($labour, 'amount'));
        $labourMargin = $labourBase * 0.15;
        $labourTotal = $labourBase + $labourMargin;

        $expensesBase = array_sum(array_column($expenses, 'amount'));
        $expensesMargin = array_sum(array_column($expenses, 'marginAmount'));
        $expensesTotal = array_sum(array_column($expenses, 'finalPrice'));

        $logisticsBase = array_sum(array_column($logistics, 'amount'));
        $logisticsMargin = array_sum(array_column($logistics, 'marginAmount'));
        $logisticsTotal = array_sum(array_column($logistics, 'finalPrice'));

        $subtotal = $materialsTotal + $labourTotal + $expensesTotal + $logisticsTotal;
        $totalAfterDiscount = $subtotal; // No discount initially
        $vatAmount = $totalAfterDiscount * 0.16;
        $grandTotal = $totalAfterDiscount + $vatAmount;

        $totals = [
            'materialsBase' => round($materialsBase, 2),
            'materialsMargin' => round($materialsMargin, 2),
            'materialsTotal' => round($materialsTotal, 2),
            'labourBase' => round($labourBase, 2),
            'labourMargin' => round($labourMargin, 2),
            'labourTotal' => round($labourTotal, 2),
            'expensesBase' => round($expensesBase, 2),
            'expensesMargin' => round($expensesMargin, 2),
            'expensesTotal' => round($expensesTotal, 2),
            'logisticsBase' => round($logisticsBase, 2),
            'logisticsMargin' => round($logisticsMargin, 2),
            'logisticsTotal' => round($logisticsTotal, 2),
            'subtotal' => round($subtotal, 2),
            'discountAmount' => 0,
            'totalAfterDiscount' => round($totalAfterDiscount, 2),
            'vatPercentage' => 16,
            'vatAmount' => round($vatAmount, 2),
            'grandTotal' => round($grandTotal, 2),
            'totalMargin' => round($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin, 2),
            'overallMarginPercentage' => ($materialsBase + $labourBase + $expensesBase + $logisticsBase) > 0 
                ? round(($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin) / ($materialsBase + $labourBase + $expensesBase + $logisticsBase) * 100, 2) 
                : 0,
        ];

        \Log::info("Totals calculated successfully", $totals);

        return [
            'materials' => $materials,
            'labour' => $labour,
            'expenses' => $expenses,
            'logistics' => $logistics,
            'totals' => $totals
        ];
    }

    /**
     * Get quote approval data for a task
     */
    public function getApprovalData(int $taskId): JsonResponse
    {
        try {
            // Get the task
            $task = EnquiryTask::find($taskId);
            if (!$task) {
                return response()->json(['message' => 'Task not found'], 404);
            }

            // Get approval record
            $approval = \DB::table('quote_approvals')
                ->where('task_id', $taskId)
                ->first();

            if (!$approval) {
                return response()->json([
                    'message' => 'No approval data found for this task',
                    'data' => null
                ], 404);
            }

            // Decode quote data
            $quoteData = json_decode($approval->quote_data, true);

            return response()->json([
                'data' => [
                    'approvalStatus' => $approval->approval_status,
                    'approvedBy' => $approval->approved_by,
                    'approvalDate' => $approval->approval_date,
                    'rejectionReason' => $approval->rejection_reason,
                    'comments' => $approval->comments,
                    'quoteAmount' => $approval->quote_amount,
                    'quoteData' => $quoteData,
                    'createdAt' => $approval->created_at,
                    'updatedAt' => $approval->updated_at,
                ],
                'message' => 'Approval data retrieved successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to retrieve approval data for task {$taskId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to retrieve approval data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit quote approval
     */
    public function submitApproval(Request $request, int $taskId): JsonResponse
    {
        \Log::info("Starting quote approval submission", [
            'task_id' => $taskId,
            'request_data' => $request->all()
        ]);

        $validator = Validator::make($request->all(), [
            'approval_status' => 'required|in:approved,rejected,pending',
            'rejection_reason' => 'nullable|string|max:1000',
            'comments' => 'nullable|string|max:1000',
            'approval_date' => 'required|date',
            'approved_by' => 'required|string|max:255',
            'quote_amount' => 'required|numeric|min:0',
            'quote_data' => 'required|array'
        ]);

        if ($validator->fails()) {
            \Log::error("Quote approval validation failed", [
                'task_id' => $taskId,
                'errors' => $validator->errors()
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get the task
            $task = EnquiryTask::find($taskId);
            if (!$task) {
                \Log::error("Quote task not found for approval", ['task_id' => $taskId]);
                return response()->json(['message' => 'Task not found'], 404);
            }

            \Log::info("Found quote task for approval", [
                'task_id' => $taskId,
                'enquiry_id' => $task->project_enquiry_id,
                'current_status' => $task->status
            ]);

            // Create or update approval record
            $approval = \DB::table('quote_approvals')->updateOrInsert(
                ['task_id' => $taskId],
                [
                    'enquiry_id' => $task->project_enquiry_id,
                    'approval_status' => $request->approval_status,
                    'approved_by' => $request->approved_by,
                    'approval_date' => $request->approval_date,
                    'rejection_reason' => $request->rejection_reason,
                    'comments' => $request->comments,
                    'quote_amount' => $request->quote_amount,
                    'quote_data' => json_encode($request->quote_data),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            \Log::info("Quote approval record created/updated", [
                'task_id' => $taskId,
                'approval_id' => $approval,
                'status' => $request->approval_status
            ]);

            // Update task status based on approval
            if ($request->approval_status === 'approved') {
                $task->update(['status' => 'completed']);
                \Log::info("Quote task marked as completed (approved)", ['task_id' => $taskId]);
            } elseif ($request->approval_status === 'rejected') {
                $task->update(['status' => 'completed']); // Still complete the task
                \Log::info("Quote task marked as completed (rejected)", ['task_id' => $taskId]);
            } else {
                $task->update(['status' => 'in_progress']); // Keep in progress for pending
                \Log::info("Quote task kept in progress (pending)", ['task_id' => $taskId]);
            }

            // Log the approval action
            \Log::info("Quote approval submitted successfully", [
                'task_id' => $taskId,
                'enquiry_id' => $task->project_enquiry_id,
                'status' => $request->approval_status,
                'approved_by' => $request->approved_by,
                'amount' => $request->quote_amount
            ]);

            return response()->json([
                'message' => 'Quote approval submitted successfully',
                'data' => [
                    'approval_status' => $request->approval_status,
                    'task_status' => $task->status,
                    'approved_by' => $request->approved_by,
                    'approval_date' => $request->approval_date
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to submit quote approval for task {$taskId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Failed to submit approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default quote structure
     */
    private function getDefaultQuoteStructure(int $taskId): array
    {
        $task = EnquiryTask::with('enquiry.client')->find($taskId);

        return [
            'projectInfo' => [
                'projectId' => $task->enquiry->enquiry_number ?? "ENQ-{$taskId}",
                'enquiryTitle' => $task->enquiry->title ?? 'Untitled Project',
                'clientName' => $task->enquiry->client->full_name ?? 'Unknown Client',
                'eventVenue' => $task->enquiry->venue ?? 'Venue TBC',
                'setupDate' => $task->enquiry->expected_delivery_date ?? 'Date TBC',
                'setDownDate' => 'TBC'
            ],
            'budgetImported' => false,
            'materials' => [],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'margins' => [
                'materials' => 20,
                'labour' => 0,
                'expenses' => 0,
                'logistics' => 0
            ],
            'discountAmount' => 0,
            'vatPercentage' => 16,
            'vatEnabled' => true,
            'totals' => [
                'materialsBase' => 0,
                'materialsMargin' => 0,
                'materialsTotal' => 0,
                'labourBase' => 0,
                'labourMargin' => 0,
                'labourTotal' => 0,
                'expensesBase' => 0,
                'expensesMargin' => 0,
                'expensesTotal' => 0,
                'logisticsBase' => 0,
                'logisticsMargin' => 0,
                'logisticsTotal' => 0,
                'subtotal' => 0,
                'discountAmount' => 0,
                'totalAfterDiscount' => 0,
                'vatPercentage' => 16,
                'vatAmount' => 0,
                'grandTotal' => 0,
                'totalMargin' => 0,
                'overallMarginPercentage' => 0
            ],
            'status' => 'draft'
        ];
    }
    public function createVersion(Request $request, $taskId)
    {
        \Log::info("createVersion called for task ID: {$taskId}");
        
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = $task->quoteData;

        if (!$quoteData) {
            return response()->json(['message' => 'Quote not found'], 404);
        }

        $latestVersion = $quoteData->versions()->max('version_number') ?? 0;
        $newVersionNumber = $latestVersion + 1;

        $version = $quoteData->versions()->create([
            'version_number' => $newVersionNumber,
            'label' => $request->input('label', 'Version ' . $newVersionNumber),
            'data' => $quoteData->toArray(),
            'created_by' => auth()->id() ?? 1 // Fallback for dev
        ]);

        return response()->json([
            'message' => 'Version created successfully',
            'data' => $version
        ]);
    }

    public function getVersions($taskId)
    {
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = $task->quoteData;

        if (!$quoteData) {
            return response()->json(['data' => []]);
        }

        $versions = $quoteData->versions()
            ->with('creator')
            ->orderBy('version_number', 'desc')
            ->get()
            ->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'label' => $version->label,
                    'created_at' => $version->created_at,
                    'created_by_name' => $version->creator->name ?? 'Unknown'
                ];
            });

        return response()->json(['data' => $versions]);
    }

    public function getVersion($taskId, $versionId)
    {
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = $task->quoteData;
        $version = QuoteVersion::findOrFail($versionId);

        if ($version->task_quote_data_id !== $quoteData->id) {
            return response()->json(['message' => 'Invalid version for this quote'], 400);
        }

        return response()->json(['data' => $version]);
    }

    public function restoreVersion($taskId, $versionId)
    {
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = $task->quoteData;
        $version = QuoteVersion::findOrFail($versionId);

        if ($version->task_quote_data_id !== $quoteData->id) {
            return response()->json(['message' => 'Invalid version for this quote'], 400);
        }

        $restoredData = $version->data;
        
        // Exclude fields that shouldn't be overwritten
        $dataToUpdate = collect($restoredData)->except(['id', 'created_at', 'updated_at', 'task_id'])->toArray();
        
        $quoteData->update($dataToUpdate);

        return response()->json([
            'message' => 'Quote restored to version ' . $version->version_number,
            'data' => $quoteData
        ]);
    }

    /**
     * Save quote approval data
     */
    public function saveApproval(Request $request, int $taskId): JsonResponse
    {
        \Log::info("API: saveApproval called for task {$taskId}", $request->all());

        $validator = Validator::make($request->all(), [
            'approval_status' => 'required|in:approved,rejected,pending',
            'rejection_reason' => 'required_if:approval_status,rejected',
            'approved_by' => 'required|string',
            'approval_date' => 'required|date',
            'quote_amount' => 'required|numeric|min:0',
            'comments' => 'nullable|string',
            'quote_data' => 'required|array'
        ]);

        if ($validator->fails()) {
            \Log::warning("API: saveApproval validation failed for task {$taskId}", $validator->errors()->toArray());
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            \Log::info("Starting saveApproval logic for task {$taskId}");
            
            // Find the Approval Task first to get the enquiry ID
            $approvalTask = EnquiryTask::find($taskId);
            if (!$approvalTask) {
                \Log::error("Approval task {$taskId} not found");
                return response()->json(['message' => 'Approval task not found'], 404);
            }
            
            \Log::info("Found approval task, enquiry ID: {$approvalTask->project_enquiry_id}");

            // Find the ORIGINAL Quote Task for this enquiry
            $originalQuoteTask = EnquiryTask::where('project_enquiry_id', $approvalTask->project_enquiry_id)
                ->where('type', 'quote')
                ->first();
                
            if (!$originalQuoteTask) {
                \Log::error("Original quote task not found for enquiry {$approvalTask->project_enquiry_id}");
                return response()->json(['message' => 'Original quote task not found'], 404);
            }

            \Log::info("Found original quote task: {$originalQuoteTask->id}");

            // Get the quote data record for the ORIGINAL task
            $quoteData = TaskQuoteData::where('enquiry_task_id', $originalQuoteTask->id)->first();

            if (!$quoteData) {
                \Log::info("Quote data missing, creating new record for task {$originalQuoteTask->id}");
                // Create new quote data if it doesn't exist (using the data sent from frontend)
                $quoteData = TaskQuoteData::create([
                    'enquiry_task_id' => $originalQuoteTask->id, // Correctly target the Original Quote Task
                    'project_info' => $request->quote_data['projectInfo'] ?? [],
                    'materials' => $request->quote_data['materials'] ?? [],
                    'labour' => $request->quote_data['labour'] ?? [],
                    'expenses' => $request->quote_data['expenses'] ?? [],
                    'logistics' => $request->quote_data['logistics'] ?? [],
                    'margins' => $request->quote_data['margins'] ?? ['materials' => 60, 'labour' => 0, 'expenses' => 0, 'logistics' => 0],
                    'totals' => $request->quote_data['totals'] ?? [],
                    'discount_amount' => $request->quote_data['discountAmount'] ?? 0,
                    'vat_percentage' => $request->quote_data['vatPercentage'] ?? 16,
                    'vat_enabled' => $request->quote_data['vatEnabled'] ?? true,
                    'budget_imported' => $request->quote_data['budgetImported'] ?? false,
                    'status' => $request->approval_status
                ]);
            } else {
                 \Log::info("Found existing quote data: {$quoteData->id}");
            }

            // Update quote status based on approval
            $quoteData->update([
                'status' => $request->approval_status,
                'approval_status' => $request->approval_status,
                'approved_by' => $request->approved_by,
                'approval_date' => $request->approval_date,
                'rejection_reason' => $request->approval_status === 'rejected' ? $request->rejection_reason : null,
                'approval_comments' => $request->comments,
                'quote_amount' => $request->quote_amount,
                'updated_at' => now()
            ]);

            // Load the original quote task again for the formatter
            $task = EnquiryTask::find($originalQuoteTask->id);

            // Create or update approval record in quote_approvals table (required for validation)
            \DB::table('quote_approvals')->updateOrInsert(
                ['task_id' => $taskId],
                [
                    'enquiry_id' => $approvalTask->project_enquiry_id,
                    'approval_status' => $request->approval_status,
                    'approved_by' => $request->approved_by,
                    'approval_date' => $request->approval_date,
                    'rejection_reason' => $request->approval_status === 'rejected' ? $request->rejection_reason : null,
                    'comments' => $request->comments,
                    'quote_amount' => $request->quote_amount,
                    'quote_data' => json_encode($request->quote_data),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            \Log::info("Successfully updated quote data {$quoteData->id} with status {$request->approval_status} and created quote_approvals record");

            return response()->json([
                'data' => $this->formatQuoteResponse($quoteData->fresh(), $task),
                'message' => 'Quote approval saved successfully'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to save quote approval', [
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to save approval data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format quote data for frontend response (camelCase)
     */
    private function formatQuoteResponse(TaskQuoteData $quoteData, ?EnquiryTask $task = null): array
    {
        $response = [
            'projectInfo' => $quoteData->project_info,
            'budgetImported' => $quoteData->budget_imported,
            'budgetImportedAt' => $quoteData->budget_imported_at,
            'budgetUpdatedAt' => $quoteData->budget_updated_at,
            'budgetVersion' => $quoteData->budget_version,
            'materials' => $quoteData->materials,
            'labour' => $quoteData->labour,
            'expenses' => $quoteData->expenses,
            'logistics' => $quoteData->logistics,
            'margins' => $quoteData->margins,
            'customMargins' => $quoteData->custom_margins ?? [],
            'discountAmount' => (float) ($quoteData->discount_amount ?? 0),
            'vatPercentage' => (float) ($quoteData->vat_percentage ?? 16),
            'vatEnabled' => (bool) ($quoteData->vat_enabled ?? true),
            'totals' => $quoteData->totals,
            'status' => $quoteData->status,
            'approvedBy' => $quoteData->approved_by,
            'approvalDate' => $quoteData->approval_date ? $quoteData->approval_date->format('Y-m-d') : null,
            'rejectionReason' => $quoteData->rejection_reason,
            'approvalComments' => $quoteData->approval_comments,
            'quoteAmount' => (float) ($quoteData->quote_amount ?? 0),
            'createdAt' => $quoteData->created_at,
            'updatedAt' => $quoteData->updated_at,
        ];

        // Ensure client name is up to date if task is provided
        if ($task && $task->enquiry && $task->enquiry->client) {
            $response['projectInfo']['clientName'] = $task->enquiry->client->full_name ?? 'Unknown Client';
        }

        return $response;
    }
}
