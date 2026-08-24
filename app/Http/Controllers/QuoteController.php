<?php

namespace App\Http\Controllers;

use App\Models\TaskQuoteData;
use App\Models\TaskBudgetData;
use App\Models\QuoteVersion;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;

class QuoteController extends Controller
{
    public function __construct(
        private readonly EnquiryWorkflowService $workflowService
    ) {}

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
     * Save quote data for a task.
     *
     * Handles both full saves (with all sections) and partial auto-saves.
     * `status` is optional — defaults to the current record's status or 'draft'.
     */
    public function saveQuoteData(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'projectInfo'            => 'nullable|array',
            'budgetImported'         => 'boolean',
            'materials'              => 'present|array',
            'materials.*'            => 'array',
            'materials.*.quantity'   => 'nullable|numeric|min:0',
            'materials.*.materials'  => 'nullable|array',
            'materials.*.materials.*'=> 'array',
            'materials.*.materials.*.quantity' => 'nullable|numeric|min:0',
            'materials.*.materials.*.days' => 'nullable|numeric|min:0',
            'materials.*.materials.*.unitPrice' => 'nullable|numeric|min:0',
            'materials.*.materials.*.marginPercentage' => 'nullable|numeric|min:0|max:1000',
            'labour'                 => 'present|array',
            'labour.*'               => 'array',
            'labour.*.quantity'      => 'nullable|numeric|min:0',
            'labour.*.unitRate'      => 'nullable|numeric|min:0',
            'labour.*.marginPercentage' => 'nullable|numeric|min:0|max:1000',
            'expenses'               => 'present|array',
            'expenses.*'             => 'array',
            'expenses.*.amount'      => 'nullable|numeric|min:0',
            'expenses.*.marginPercentage' => 'nullable|numeric|min:0|max:1000',
            'logistics'              => 'present|array',
            'logistics.*'            => 'array',
            'logistics.*.quantity'   => 'nullable|numeric|min:0',
            'logistics.*.unitRate'   => 'nullable|numeric|min:0',
            'logistics.*.marginPercentage' => 'nullable|numeric|min:0|max:1000',
            'margins'                => 'required|array',
            'margins.materials'      => 'numeric|min:0|max:1000',
            'margins.labour'         => 'numeric|min:0|max:1000',
            'margins.expenses'       => 'numeric|min:0|max:1000',
            'margins.logistics'      => 'numeric|min:0|max:1000',
            'customMargins'          => 'nullable|array',
            'discountAmount'         => 'numeric|min:0',
            'vatPercentage'          => 'numeric|min:0|max:100',
            'vatEnabled'             => 'boolean',
            'totals'                 => 'required|array',
            // status is optional — the frontend does not always include it.
            // Approval states (approved/rejected) are never client-settable here;
            // they are only written by the approval endpoints.
            'status'                 => 'nullable|in:draft,pending',
            'viewerSettings'         => 'nullable|array',
            // Margin governance audit trail
            'justification'          => 'nullable|string|max:2000',
            'justificationHistory'   => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // Preserve existing status unless the caller explicitly sends a new one
            $existing = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

            if ($locked = $this->approvedQuoteLockResponse($existing)) {
                return $locked;
            }

            $status = $request->status ?? $existing?->status ?? 'draft';
            $sections = $this->canonicalizeQuoteSections([
                'materials' => $request->materials,
                'labour' => $request->labour,
                'expenses' => $request->expenses,
                'logistics' => $request->logistics,
            ]);
            $totals = $this->recalculateTotals(
                $sections,
                $request->margins,
                (float) ($request->discountAmount ?? 0),
                $request->vatPercentage !== null ? (float) $request->vatPercentage : null,
                (bool) ($request->vatEnabled ?? true)
            );

            $quoteData = TaskQuoteData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info'          => $request->projectInfo ?? $existing?->project_info ?? [],
                    'budget_imported'        => $request->budgetImported ?? $existing?->budget_imported ?? false,
                    'materials'              => $sections['materials'],
                    'labour'                 => $sections['labour'],
                    'expenses'               => $sections['expenses'],
                    'logistics'              => $sections['logistics'],
                    'margins'                => $request->margins,
                    'custom_margins'         => $request->customMargins ?? $existing?->custom_margins ?? [],
                    'discount_amount'        => $request->discountAmount ?? 0,
                    'vat_percentage'         => $request->vatPercentage ?? 16,
                    'vat_enabled'            => $request->vatEnabled ?? true,
                    // Browser totals are display-only. Persist the canonical
                    // server calculation from atomic quantity/rate inputs.
                    'totals'                 => $totals,
                    'status'                 => $status,
                    'viewer_settings'        => $request->viewerSettings ?? $existing?->viewer_settings ?? [],
                    // Persist governance fields
                    'justification'          => $request->justification ?? $existing?->justification,
                    'justification_history'  => $request->justificationHistory ?? $existing?->justification_history ?? [],
                    'updated_at'             => now(),
                ]
            );

            return response()->json([
                'data'    => $this->formatQuoteResponse($quoteData->fresh()),
                'message' => 'Quote data saved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save quote data',
                'error'   => $e->getMessage(),
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

             // Transform budget data to quote format.
             // Default margins for initial import should match frontend defaults (60%)
             $defaultMargins = [
                 'materials' => 60,
                 'labour' => 60,
                 'expenses' => 60,
                 'logistics' => 60
             ];
             $quoteData = $this->transformBudgetToQuote($budgetData, $defaultMargins);

             \Log::info("Transformed budget data, creating/updating quote");

             // Create or update quote with imported data
             $existingQuote = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

             if ($locked = $this->approvedQuoteLockResponse($existingQuote)) {
                 return $locked;
             }

             if ($existingQuote) {
                 \Log::info("Merging new budget into existing quote {$existingQuote->id}");
                 
                 $existingMaterials = $existingQuote->materials ?? [];
                 $mergedMaterials = $this->mergeQuoteMaterials($existingMaterials, $quoteData['materials']);
                 
                 // Merge items categories to preserve margins
                 $mergedLabour = $this->mergeSimpleItems($existingQuote->labour ?? [], $quoteData['labour']);
                 $mergedExpenses = $this->mergeSimpleItems($existingQuote->expenses ?? [], $quoteData['expenses']);
                 $mergedLogistics = $this->mergeSimpleItems($existingQuote->logistics ?? [], $quoteData['logistics']);
                 
                 // Preserve global margins if they exist, otherwise use defaults
                 $margins = $existingQuote->margins ?? ['materials' => 60, 'labour' => 0, 'expenses' => 0, 'logistics' => 0];

                 // Update quote data for recalculation
                 $tempQuoteData = $quoteData;
                 $tempQuoteData['materials'] = $mergedMaterials;
                 $tempQuoteData['labour'] = $mergedLabour;
                 $tempQuoteData['expenses'] = $mergedExpenses;
                 $tempQuoteData['logistics'] = $mergedLogistics;

                 $totals = $this->recalculateTotals(
                     $tempQuoteData,
                     $margins,
                     (float) ($existingQuote->discount_amount ?? 0),
                     $existingQuote->vat_percentage !== null ? (float) $existingQuote->vat_percentage : null,
                     (bool) ($existingQuote->vat_enabled ?? true)
                 );
                 
                 $existingQuote->update([
                     'project_info' => $budgetData->project_info,
                     'budget_imported' => true,
                     'budget_imported_at' => now(),
                     'budget_updated_at' => $budgetData->updated_at,
                     'budget_version' => 'v_' . $budgetData->id . '_' . $budgetData->updated_at->format('YmdHis'),
                     'materials' => $mergedMaterials,
                     'labour' => $mergedLabour,
                     'expenses' => $mergedExpenses,
                     'logistics' => $mergedLogistics,
                     'totals' => $totals,
                    'viewer_settings' => $existingQuote->viewer_settings ?? [],
                    'updated_at' => now()
                 ]);
                 $quote = $existingQuote;
             } else {
                 $quote = TaskQuoteData::create([
                     'enquiry_task_id' => $taskId,
                     'project_info' => $budgetData->project_info,
                     'budget_imported' => true,
                     'budget_imported_at' => now(),
                     'budget_updated_at' => $budgetData->updated_at,
                     'budget_version' => 'v_' . $budgetData->id . '_' . $budgetData->updated_at->format('YmdHis'),
                     'custom_margins' => [],
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
                 ]);
             }

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

            $newQuoteData = $this->transformBudgetToQuote($budgetData);

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

            if ($locked = $this->approvedQuoteLockResponse($quoteData)) {
                return $locked;
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

            // Get current margins to use as baseline for new items
            $currentMargins = $quoteData->margins ?? [
                'materials' => 60,
                'labour' => 60,
                'expenses' => 60,
                'logistics' => 60
            ];

            // Get fresh data from budget, initialized with current margins
            $newQuoteData = $this->transformBudgetToQuote($budgetData, $currentMargins);

            // MERGE LOGIC:
            // We want to keep the NEW budget structure (items, quantities, costs)
            // But preserve the OLD quote customizations (margins, days)
            
            $existingMaterials = $quoteData->materials ?? [];
            $mergedMaterials = $this->mergeQuoteMaterials($existingMaterials, $newQuoteData['materials'], $currentMargins['materials'] ?? 60);
            
            // Merge items categories to preserve margins
            $mergedLabour = $this->mergeSimpleItems($quoteData->labour ?? [], $newQuoteData['labour'], $currentMargins['labour'] ?? 0);
            $mergedExpenses = $this->mergeSimpleItems($quoteData->expenses ?? [], $newQuoteData['expenses'], $currentMargins['expenses'] ?? 0);
            $mergedLogistics = $this->mergeSimpleItems($quoteData->logistics ?? [], $newQuoteData['logistics'], $currentMargins['logistics'] ?? 0);
            
            // Preserve global margins if they exist, otherwise use defaults
            $margins = $currentMargins;

            // Update newQuoteData with merged items
            $newQuoteData['materials'] = $mergedMaterials;
            $newQuoteData['labour'] = $mergedLabour;
            $newQuoteData['expenses'] = $mergedExpenses;
            $newQuoteData['logistics'] = $mergedLogistics;

            $totals = $this->recalculateTotals(
                $newQuoteData,
                $margins,
                (float) ($quoteData->discount_amount ?? 0),
                $quoteData->vat_percentage !== null ? (float) $quoteData->vat_percentage : null,
                (bool) ($quoteData->vat_enabled ?? true)
            );

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

    private function mergeQuoteMaterials(array $existingMaterials, array $newMaterials, float $defaultMargin = 60): array
    {
        $existingIdMap = [];
        $existingDescMap = [];
        $existingElementMap = [];
        
        // Map existing items by ID and by normalized Description
        foreach ($existingMaterials as $element) {
            $eId = (string)($element['id'] ?? '');
            if (!empty($eId)) {
                $existingElementMap[$eId] = $element;
            }
            
            foreach ($element['materials'] ?? [] as $material) {
                // Track which element this material belongs to
                $material['element_id'] = $element['id'];
                
                if (!empty($material['id'])) {
                    $existingIdMap[(string)$material['id']] = $material;
                }
                $descKey = trim($material['description'] ?? '');
                if (!empty($descKey)) {
                    $existingDescMap[$descKey] = $material;
                }
            }
        }

        $newElementIds = [];
        // 1. Process New Materials from Budget
        foreach ($newMaterials as &$element) {
            $newElementIds[$element['id']] = true;
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
                    // PRESERVE MATERIAL CUSTOMIZATIONS
                    $material['marginPercentage'] = $match['marginPercentage'] ?? $defaultMargin;
                    $material['days'] = $match['days'] ?? 1;
                    
                    if (!empty($match['description'])) {
                        $material['description'] = $match['description'];
                    }
                    
                    // Recalculate material prices
                    $material['totalPrice'] = ($material['quantity'] ?? 0) * ($material['unitPrice'] ?? 0) * ($material['days'] ?? 1);
                    $material['marginAmount'] = $material['totalPrice'] * ($material['marginPercentage'] / 100);
                    $material['finalPrice'] = $material['totalPrice'] + $material['marginAmount'];
                    
                    // Remove from maps to avoid duplication
                    if (!empty($match['id'])) unset($existingIdMap[(string)$match['id']]);
                    unset($existingDescMap[trim($match['description'] ?? '')]);
                } else {
                    // Ensure even new items use the category default if it was changed
                    $material['marginPercentage'] = $defaultMargin;
                    $material['totalPrice'] = ($material['quantity'] ?? 0) * ($material['unitPrice'] ?? 0) * ($material['days'] ?? 1);
                    $material['marginAmount'] = $material['totalPrice'] * ($material['marginPercentage'] / 100);
                    $material['finalPrice'] = $material['totalPrice'] + $material['marginAmount'];
                }
            }
            
            // PRESERVE ELEMENT-LEVEL CUSTOMIZATIONS (Header Qty & Description)
            $eId = (string)($element['id'] ?? '');
            $oldElement = (!empty($eId) && isset($existingElementMap[$eId])) ? $existingElementMap[$eId] : null;
            
            if ($oldElement) {
                // If it existed before, keep the user's manual adjustments
                $element['quantity'] = $oldElement['quantity'] ?? 1;
                $element['description'] = $oldElement['description'] ?? '';
                // Also preserve custom name if edited
                if (!empty($oldElement['name']) && $oldElement['name'] !== $element['name']) {
                    $element['name'] = $oldElement['name'];
                }
            } else {
                // For new elements from budget, initialize with defaults
                $element['quantity'] = $element['quantity'] ?? 1;
                $element['description'] = $element['description'] ?? '';
            }

            // Re-sum element totals and apply ELEMENT QUANTITY scaling
            $sumBase = array_sum(array_column($element['materials'], 'totalPrice'));
            $sumMargin = array_sum(array_column($element['materials'], 'marginAmount'));
            
            $element['baseTotal'] = (float)$sumBase * (float)$element['quantity'];
            $element['marginAmount'] = (float)$sumMargin * (float)$element['quantity'];
            $element['finalTotal'] = $element['baseTotal'] + $element['marginAmount'];
            $element['marginPercentage'] = $element['baseTotal'] > 0 ? ($element['marginAmount'] / $element['baseTotal'] * 100) : 60;
        }

        return $newMaterials;
    }

    /**
     * Merge simple items (Expenses, Logistics) to preserve margins
     */
    private function mergeSimpleItems(array $existingItems, array $newItems, float $defaultMargin = 0): array
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
                $item['marginPercentage'] = $match['marginPercentage'] ?? $defaultMargin;
                
                // Preserve user-defined quantity and days if they were modified in the quote
                if (isset($match['quantity'])) $item['quantity'] = $match['quantity'];
                if (isset($match['days'])) $item['days'] = $match['days'];
                
                if (!empty($match['type'])) $item['type'] = $match['type'];
                if (!empty($match['description'])) $item['description'] = $match['description'];
                
                $baseAmount = ($item['amount'] ?? 0) > 0 ? $item['amount'] : ( ($item['unitRate'] ?? 0) * ($item['quantity'] ?? 1) );
                $item['amount'] = $baseAmount;
                $item['marginAmount'] = $baseAmount * ($item['marginPercentage'] / 100);
                $item['finalPrice'] = $baseAmount + $item['marginAmount'];
                
                if (!empty($match['id'])) unset($existingIdMap[(string)$match['id']]);
                unset($existingDescMap[trim($match['description'] ?? $match['type'] ?? '')]);
            } else {
                // Apply default margin to new items if it was changed at category level
                $item['marginPercentage'] = $defaultMargin;
                $baseAmount = ($item['amount'] ?? 0) > 0 ? $item['amount'] : ( ($item['unitRate'] ?? 0) * ($item['quantity'] ?? 1) );
                $item['amount'] = $baseAmount;
                $item['marginAmount'] = $baseAmount * ($item['marginPercentage'] / 100);
                $item['finalPrice'] = $baseAmount + $item['marginAmount'];
            }
        }

        return $newItems;
    }

    /**
     * Rebuild every derived line value from the quote's atomic inputs.
     *
     * A client may send derived fields because the editor needs immediate
     * feedback, but those values are never financial authority.
     */
    private function canonicalizeQuoteSections(array $sections): array
    {
        $materials = array_map(function (array $element): array {
            $elementQuantity = max(0, (float) ($element['quantity'] ?? 1));
            $lines = array_map(function (array $line): array {
                $quantity = max(0, (float) ($line['quantity'] ?? 0));
                $days = max(0, (float) ($line['days'] ?? 1));
                $unitPrice = max(0, (float) ($line['unitPrice'] ?? 0));
                $marginPercentage = max(0, (float) ($line['marginPercentage'] ?? 0));
                $base = $quantity * $days * $unitPrice;
                $margin = $base * ($marginPercentage / 100);

                return array_merge($line, [
                    'quantity' => $quantity,
                    'days' => $days,
                    'unitPrice' => $unitPrice,
                    'marginPercentage' => $marginPercentage,
                    'totalPrice' => round($base, 2),
                    'marginAmount' => round($margin, 2),
                    'finalPrice' => round($base + $margin, 2),
                ]);
            }, array_values(array_filter($element['materials'] ?? [], 'is_array')));

            $visibleLines = array_filter(
                $lines,
                fn (array $line): bool => ($line['isVisible'] ?? true) !== false
            );
            $base = array_sum(array_column($visibleLines, 'totalPrice')) * $elementQuantity;
            $margin = array_sum(array_column($visibleLines, 'marginAmount')) * $elementQuantity;

            return array_merge($element, [
                'quantity' => $elementQuantity,
                'materials' => $lines,
                'baseTotal' => round($base, 2),
                'marginAmount' => round($margin, 2),
                'marginPercentage' => $base > 0 ? round(($margin / $base) * 100, 4) : 0,
                'finalTotal' => round($base + $margin, 2),
            ]);
        }, array_values(array_filter($sections['materials'] ?? [], 'is_array')));

        $canonicalizeSimpleItems = function (array $items, bool $deriveAmountFromRate): array {
            return array_map(function (array $item) use ($deriveAmountFromRate): array {
                $quantity = max(0, (float) ($item['quantity'] ?? 0));
                $unitRate = max(0, (float) ($item['unitRate'] ?? 0));
                $base = $deriveAmountFromRate
                    ? $quantity * $unitRate
                    : max(0, (float) ($item['amount'] ?? 0));
                $marginPercentage = max(0, (float) ($item['marginPercentage'] ?? 0));
                $margin = $base * ($marginPercentage / 100);

                return array_merge($item, [
                    'amount' => round($base, 2),
                    'marginPercentage' => $marginPercentage,
                    'marginAmount' => round($margin, 2),
                    'finalPrice' => round($base + $margin, 2),
                ]);
            }, array_values(array_filter($items, 'is_array')));
        };

        return [
            'materials' => $materials,
            'labour' => $canonicalizeSimpleItems($sections['labour'] ?? [], true),
            'expenses' => $canonicalizeSimpleItems($sections['expenses'] ?? [], false),
            'logistics' => $canonicalizeSimpleItems($sections['logistics'] ?? [], true),
        ];
    }

    private function recalculateTotals(
        array $quoteData,
        array $margins,
        float $discountAmount = 0,
        ?float $vatPercentage = null,
        bool $vatEnabled = true
    ): array {
        $visible = fn (array $items): array => array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item) && ($item['isVisible'] ?? true) !== false
        ));
        $materials = $visible($quoteData['materials']);
        $labour = $visible($quoteData['labour']);
        $expenses = $visible($quoteData['expenses']);
        $logistics = $visible($quoteData['logistics']);

        $materialsBase = array_sum(array_column($materials, 'baseTotal'));
        $materialsMargin = array_sum(array_column($materials, 'marginAmount'));
        $materialsTotal = array_sum(array_column($materials, 'finalTotal'));

        $labourBase = array_sum(array_column($labour, 'amount'));
        $labourMargin = array_sum(array_column($labour, 'marginAmount'));
        $labourTotal = array_sum(array_column($labour, 'finalPrice'));

        $expensesBase = array_sum(array_column($expenses, 'amount'));
        $expensesMargin = array_sum(array_column($expenses, 'marginAmount'));
        $expensesTotal = array_sum(array_column($expenses, 'finalPrice'));

        $logisticsBase = array_sum(array_column($logistics, 'amount'));
        $logisticsMargin = array_sum(array_column($logistics, 'marginAmount'));
        $logisticsTotal = array_sum(array_column($logistics, 'finalPrice'));

        $subtotal = $materialsTotal + $labourTotal + $expensesTotal + $logisticsTotal;
        $discount = min(max($discountAmount, 0), $subtotal);
        $totalAfterDiscount = $subtotal - $discount;
        $vatPct = $vatEnabled ? ($vatPercentage ?? 16) : 0;
        $vatAmount = $totalAfterDiscount * ($vatPct / 100);
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
            'discountAmount' => round($discount, 2),
            'totalAfterDiscount' => round($totalAfterDiscount, 2),
            'vatPercentage' => $vatPct,
            'vatAmount' => round($vatAmount, 2),
            'grandTotal' => round($grandTotal, 2),
            'totalMargin' => round($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin, 2),
            'overallMarginPercentage' => ($materialsBase + $labourBase + $expensesBase + $logisticsBase) > 0
                ? round(($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin - $discount) / ($materialsBase + $labourBase + $expensesBase + $logisticsBase) * 100, 2)
                : 0
        ];
    }

    /**
     * Transform budget data to quote format
     */
    private function transformBudgetToQuote(TaskBudgetData $budgetData, ?array $preferredMargins = null): array
    {
        \Log::info("Starting budget data transformation");

        $m_margin = $preferredMargins['materials'] ?? 60;
        $l_margin = $preferredMargins['labour'] ?? 0;
        $e_margin = $preferredMargins['expenses'] ?? 0;
        $lo_margin = $preferredMargins['logistics'] ?? 0;

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
                    $totalPrice = $material['totalPrice'] ?? 0;
                    $marginAmount = $totalPrice * ($m_margin / 100);
                    $elementMaterials[] = [
                        'id' => $material['id'],
                        'description' => $material['description'],
                        'unitOfMeasurement' => $material['unitOfMeasurement'] ?? $material['unit_of_measurement'] ?? '',
                        'quantity' => $material['quantity'],
                        'days' => 1, // Default days
                        'unitPrice' => $material['unitPrice'] ?? 0,
                        'totalPrice' => $totalPrice,
                        'isAddition' => $material['isAddition'] ?? false,
                        'marginPercentage' => $m_margin,
                        'marginAmount' => $marginAmount,
                        'finalPrice' => $totalPrice + $marginAmount
                    ];
                }

                $sumBase = array_sum(array_column($elementMaterials, 'totalPrice')) * ($element['quantity'] ?? 1);
                $sumMargin = array_sum(array_column($elementMaterials, 'marginAmount')) * ($element['quantity'] ?? 1);

                $materials[] = [
                    'id' => $element['id'],
                    'templateId' => $element['templateId'] ?? null,
                    'name' => $element['name'],
                    'description' => $element['description'] ?? '',
                    'quantity' => $element['quantity'] ?? 1,
                    'materials' => $elementMaterials,
                    'baseTotal' => $sumBase,
                    'marginPercentage' => $m_margin,
                    'marginAmount' => $sumMargin,
                    'finalTotal' => $sumBase + $sumMargin
                ];
            }
        }


        // Transform labour (no individual margins)
        if ($budgetData->labour_data) {
            \Log::info("Transforming labour data: " . count($budgetData->labour_data) . " items");
            $labour = array_map(function ($item) use ($l_margin) {
                $marginAmount = $item['amount'] * ($l_margin / 100);
                return [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unitRate' => $item['unitRate'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'category' => $item['category'] ?? 'General',
                    'marginPercentage' => $l_margin,
                    'marginAmount' => $marginAmount,
                    'finalPrice' => $item['amount'] + $marginAmount
                ];
            }, $budgetData->labour_data);
        }

        // Transform expenses with margins
        if ($budgetData->expenses_data) {
            \Log::info("Transforming expenses data: " . count($budgetData->expenses_data) . " items");
            $expenses = array_map(function ($item) use ($e_margin) {
                $marginAmount = $item['amount'] * ($e_margin / 100);
                return [
                    'id' => $item['id'],
                    'description' => $item['description'],
                    'category' => $item['category'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'marginPercentage' => $e_margin,
                    'marginAmount' => $marginAmount,
                    'finalPrice' => $item['amount'] + $marginAmount
                ];
            }, $budgetData->expenses_data);
        }

        // Transform logistics with margins
        if ($budgetData->logistics_data) {
            \Log::info("Transforming logistics data: " . count($budgetData->logistics_data) . " items");
            $logistics = array_map(function ($item) use ($lo_margin) {
                $marginAmount = $item['amount'] * ($lo_margin / 100);
                return [
                    'id' => $item['id'],
                    'vehicleReg' => $item['vehicleReg'] ?? '',
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unitRate' => $item['unitRate'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'marginPercentage' => $lo_margin,
                    'marginAmount' => $marginAmount,
                    'finalPrice' => $item['amount'] + $marginAmount
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

            // Fresh financial context for the decision screen: budget baseline,
            // implied margin, and the 70% mobilization amount — recomputed now,
            // not at upload time, so a revised budget is reflected.
            $task->loadMissing('enquiry');
            $financialContext = ($task->enquiry && $approval->quote_amount)
                ? app(\App\Modules\Projects\Services\QuoteInsightsService::class)
                    ->budgetComparison($task->enquiry, (float) $approval->quote_amount)
                : null;

            return response()->json([
                'data' => [
                    'financialContext' => $financialContext,
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
            // approved_by / approval_date are server-set from the authenticated
            // user; client-sent values are accepted for compatibility but ignored.
            'approval_date' => 'nullable|date',
            'approved_by' => 'nullable|string|max:255',
            'quote_amount' => 'required|numeric|min:0',
            'quote_data' => 'required|array'
        ]);

        if ($denied = $this->approvalPermissionDeniedResponse($request)) {
            return $denied;
        }

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

            // Create or update approval record. Approver identity and date come
            // from the authenticated session, never from the request body.
            $approvedBy   = $request->user()->name;
            $approvalDate = now();

            $approval = \DB::table('quote_approvals')->updateOrInsert(
                ['task_id' => $taskId],
                [
                    'enquiry_id' => $task->project_enquiry_id,
                    'approval_status' => $request->approval_status,
                    'approved_by' => $approvedBy,
                    'approval_date' => $approvalDate,
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

            $targetStatus = $request->approval_status === 'approved' ? 'completed' : 'in_progress';
            $this->workflowService->updateTaskStatus(
                $task->id,
                $targetStatus,
                $request->user()->id,
                "Quote approval decision: {$request->approval_status}"
            );
            $task->refresh();

            // Log the approval action
            \Log::info("Quote approval submitted successfully", [
                'task_id' => $taskId,
                'enquiry_id' => $task->project_enquiry_id,
                'status' => $request->approval_status,
                'approved_by' => $approvedBy,
                'amount' => $request->quote_amount
            ]);

            return response()->json([
                'message' => 'Quote approval submitted successfully',
                'data' => [
                    'approval_status' => $request->approval_status,
                    'task_status' => $task->status,
                    'approved_by' => $approvedBy,
                    'approval_date' => $approvalDate->toISOString()
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
     * Server-side scope → quote sync.
     *
     * Reads the latest project_scope from the enquiry (via the deliverables table),
     * then:
     *   - Creates quote material elements for new scope items
     *   - Updates name/category on elements whose scope item was renamed/reclassified
     *   - Removes elements whose scopeId no longer exists in scope (manual elements kept)
     *   - Applies hire-days from expected_delivery_date to new empty line items
     *
     * Returns the updated quote data so the frontend can replace its local state.
     */
    public function syncScope(int $taskId): JsonResponse
    {
        try {
            $task = EnquiryTask::with('enquiry.deliverables')->find($taskId);
            if (!$task) {
                return response()->json(['message' => 'Task not found'], 404);
            }

            if ($locked = $this->approvedQuoteLockResponse(TaskQuoteData::where('enquiry_task_id', $taskId)->first())) {
                return $locked;
            }

            $enquiry   = $task->enquiry;
            $rawScope  = $enquiry?->project_scope ?? [];

            // Build structured map: scopeId → { name, classification }
            // project_scope accessor always returns structured arrays from the deliverables table
            $scopeMap  = [];
            foreach ($rawScope as $item) {
                $id  = $item['uuid'] ?? $item['id'] ?? null;
                $cls = strtoupper($item['classification'] ?? 'PRE-DEFINED');
                $nm  = $item['name'] ?? '';
                if ($id && $nm) {
                    $scopeMap[$id] = ['name' => $nm, 'classification' => $cls];
                }
            }

            // Margin and template defaults delegated to single source of truth
            // @see App\Constants\ScopeClassification

            // Hire-days from delivery date
            $hireDays = 1;
            if ($enquiry?->expected_delivery_date) {
                $diff = now()->diffInDays($enquiry->expected_delivery_date, false);
                $hireDays = max(1, (int) $diff);
            }

            $quoteRecord = TaskQuoteData::firstOrCreate(
                ['enquiry_task_id' => $taskId],
                $this->getDefaultQuoteStructure($taskId)
            );

            $materials        = is_array($quoteRecord->materials) ? $quoteRecord->materials : [];
            $existingScopeIds = [];
            $result           = ['added' => [], 'updated' => [], 'removed' => []];

            // 1. Update existing elements
            $updatedMaterials = [];
            foreach ($materials as $element) {
                $sid = $element['scopeId'] ?? null;
                if ($sid && isset($scopeMap[$sid])) {
                    $changed = false;
                    if (($element['name'] ?? '') !== $scopeMap[$sid]['name']) {
                        $element['name'] = $scopeMap[$sid]['name'];
                        $changed = true;
                    }
                    if (($element['category'] ?? '') !== $scopeMap[$sid]['classification']) {
                        $element['category'] = $scopeMap[$sid]['classification'];
                        $changed = true;
                    }
                    if ($changed) $result['updated'][] = $element['name'];
                    $existingScopeIds[$sid] = true;
                    $updatedMaterials[] = $element;
                } elseif (!$sid) {
                    // Manually-added element — always keep
                    $updatedMaterials[] = $element;
                } else {
                    // scopeId set but no longer in scope — remove
                    $result['removed'][] = $element['name'] ?? $sid;
                }
            }

            // 2. Add new scope items not yet in the quote
            foreach ($scopeMap as $sid => $info) {
                if (isset($existingScopeIds[$sid])) continue;
                $cls    = $info['classification'];
                $margin = \App\Constants\ScopeClassification::defaultMargin($cls);
                $tmpl   = \App\Constants\ScopeClassification::toTemplateId($cls);
                $updatedMaterials[] = [
                    'id'               => (string) \Illuminate\Support\Str::uuid(),
                    'scopeId'          => $sid,
                    'name'             => $info['name'],
                    'category'         => $cls,
                    'description'      => '',
                    'quantity'         => 1,
                    'baseTotal'        => 0,
                    'marginAmount'     => 0,
                    'marginPercentage' => $margin,
                    'finalTotal'       => 0,
                    'templateId'       => $tmpl,
                    'materials'        => [
                        [
                            'id'               => (string) \Illuminate\Support\Str::uuid(),
                            'description'      => '',
                            'unitPrice'        => 0,
                            'quantity'         => 1,
                            'days'             => $hireDays,
                            'totalPrice'       => 0,
                            'marginPercentage' => $margin,
                            'marginAmount'     => 0,
                            'finalPrice'       => 0,
                            'isVisible'        => true,
                            'isAddition'       => false,
                        ]
                    ],
                ];
                $result['added'][] = $info['name'];
            }

            $quoteRecord->update(['materials' => $updatedMaterials]);

            $totalChanges = count($result['added']) + count($result['updated']) + count($result['removed']);

            return response()->json([
                'data'    => $this->formatQuoteResponse($quoteRecord->fresh(), $task),
                'changes' => $result,
                'message' => $totalChanges > 0
                    ? implode(' · ', array_filter([
                        count($result['added'])   ? count($result['added'])   . ' added'   : null,
                        count($result['updated']) ? count($result['updated']) . ' updated' : null,
                        count($result['removed']) ? count($result['removed']) . ' removed' : null,
                    ]))
                    : 'Quote already in sync with scope',
            ]);

        } catch (\Exception $e) {
            \Log::error("Scope sync failed for task {$taskId}: " . $e->getMessage());
            return response()->json([
                'message' => 'Scope sync failed',
                'error'   => $e->getMessage(),
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
                'materials' => 30,
                'labour'    => 30,
                'expenses'  => 10,
                'logistics' => 20,
                'global'    => 30,
            ],
            'discountAmount'       => 0,
            'vatPercentage'        => 16,
            'vatEnabled'           => true,
            'justification'         => null,
            'justification_history' => [],
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
        
        $quoteData->recordCustomAction('version_created', [
            'version_number' => $newVersionNumber,
            'label' => $version->label
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
            ->map(function ($version) use ($taskId) {
                return [
                    'id'              => $version->id,
                    'version_number'  => $version->version_number,
                    'label'           => $version->label,
                    'created_at'      => $version->created_at,
                    'created_by_name' => $version->creator->name ?? 'Unknown',
                    'file_url'        => isset($version->data['excel_quote_file'])
                        ? $this->excelQuoteSignedUrl((int) $taskId, $version->id)
                        : ($version->data['file_url'] ?? null),
                    'amount'          => $version->data['excel_quote_amount'] ?? null,
                    'filename'        => $version->data['excel_quote_filename'] ?? null,
                    'revision_notes'  => $version->data['revision_notes'] ?? null,
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

        if ($locked = $this->approvedQuoteLockResponse($quoteData)) {
            return $locked;
        }

        $restoredData = $version->data;

        // Exclude fields that shouldn't be overwritten. Approval fields stay with
        // the live record: restoring a snapshot must never resurrect (or erase)
        // an approval decision — only the approval endpoints write those.
        $dataToUpdate = collect($restoredData)->except([
            'id', 'created_at', 'updated_at', 'task_id',
            'status', 'approval_status', 'approved_by', 'approval_date',
            'approval_comments', 'rejection_reason',
        ])->toArray();

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
            // approved_by / approval_date are server-set from the authenticated
            // user; client-sent values are accepted for compatibility but ignored.
            'approved_by' => 'nullable|string',
            'approval_date' => 'nullable|date',
            'quote_amount' => 'required|numeric|min:0',
            'comments' => 'nullable|string',
            'quote_data' => 'nullable|array'
        ]);

        if ($denied = $this->approvalPermissionDeniedResponse($request)) {
            return $denied;
        }

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
            $approvalTask = EnquiryTask::with('enquiry')->find($taskId);
            if (!$approvalTask) {
                \Log::error("Approval task {$taskId} not found");
                return response()->json(['message' => 'Approval task not found'], 404);
            }

            // Zero-value approvals are allowed for projects proceeding without
            // a priced quote. Finance must still enter a positive billing amount
            // later before payments and percentage-based release can be tracked.

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

            $isExcelMode = $quoteData && $quoteData->quote_mode === 'excel_upload';

            // Approver identity and date come from the authenticated session,
            // never from the request body.
            $approvedBy   = $request->user()->name;
            $approvalDate = now();

            // Update quote status based on approval.
            // For Excel-mode quotes, Finance may override the declared amount here —
            // that override becomes the financial anchor for all downstream gates.
            $quoteData->update([
                'status'           => $request->approval_status,
                'approval_status'  => $request->approval_status,
                'approved_by'      => $approvedBy,
                'approval_date'    => $approvalDate,
                'rejection_reason' => $request->approval_status === 'rejected' ? $request->rejection_reason : null,
                'approval_comments'=> $request->comments,
                'quote_amount'     => $request->quote_amount,
                'updated_at'       => now()
            ]);

            // For Excel mode: keep excel_quote_amount in sync with the Finance-agreed figure.
            if ($isExcelMode && $request->approval_status === 'approved') {
                $quoteData->update(['excel_quote_amount' => $request->quote_amount]);
            }

            // Anchor client_approved_quote on the enquiry so FinanceService.resolveQuoteBasis
            // uses the Priority-1 path (client_approved_quote > 0) instead of the fallback.
            if ($request->approval_status === 'approved' && $approvalTask->enquiry) {
                $approvalTask->enquiry->update(['client_approved_quote' => $request->quote_amount]);
            }

            // Automatically create a frozen baseline snapshot upon quote approval
            if ($request->approval_status === 'approved') {
                $label = $isExcelMode
                    ? 'Baseline Approved (Excel: ' . ($quoteData->excel_quote_filename ?? 'file') . ')'
                    : 'Baseline Approved';
                $this->createFrozenSnapshot($quoteData, $label);
            }

            // Load the original quote task again for the formatter
            $task = EnquiryTask::find($originalQuoteTask->id);

            // Create or update approval record in quote_approvals table (required for validation)
            // Snapshot the canonical server record, never the optional browser
            // payload. Excel approvals can legitimately submit no quote_data;
            // persisting that as JSON "null" made downstream Materials unreadable.
            $canonicalApprovalSnapshot = $this->formatQuoteResponse($quoteData->fresh(), $task);
            \DB::table('quote_approvals')->updateOrInsert(
                ['task_id' => $taskId],
                [
                    'enquiry_id' => $approvalTask->project_enquiry_id,
                    'approval_status' => $request->approval_status,
                    'approved_by' => $approvedBy,
                    'approval_date' => $approvalDate,
                    'rejection_reason' => $request->approval_status === 'rejected' ? $request->rejection_reason : null,
                    'comments' => $request->comments,
                    'quote_amount' => $request->quote_amount,
                    'quote_data' => json_encode($canonicalApprovalSnapshot, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            // The approval decision itself is the final business action. Do not
            // ask the user to click a second "Complete Task" button. A rejection
            // leaves approval open and returns Quote Preparation for revision so
            // downstream execution tasks remain blocked.
            if ($request->approval_status === 'approved') {
                $this->workflowService->updateTaskStatus(
                    $approvalTask->id,
                    'completed',
                    $request->user()->id,
                    'Quote approved'
                );
            } else {
                $this->workflowService->updateTaskStatus(
                    $approvalTask->id,
                    'in_progress',
                    $request->user()->id,
                    "Quote decision: {$request->approval_status}"
                );

                if ($request->approval_status === 'rejected') {
                    $this->workflowService->updateTaskStatus(
                        $originalQuoteTask->id,
                        'in_progress',
                        $request->user()->id,
                        'Quote rejected and returned for revision'
                    );
                }
            }

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
     * Format quote data for frontend response (camelCase).
     * Always includes all fields the frontend model expects.
     */
    private function formatQuoteResponse(TaskQuoteData $quoteData, ?EnquiryTask $task = null): array
    {
        if ($task) {
            $task->loadMissing('enquiry.client', 'enquiry.deliverables');
        } else {
            $task = $quoteData->enquiryTask;
            if ($task) {
                $task->loadMissing('enquiry.client', 'enquiry.deliverables');
            }
        }

        $projectInfo = $quoteData->project_info ?? [];
        if ($task && $task->enquiry) {
            // Always refresh scope from the deliverables table so edits in the enquiry
            // are reflected immediately when the quote is re-fetched.
            $projectInfo['projectScope']  = $task->enquiry->project_scope;
            $projectInfo['clientName']    = $task->enquiry->client->full_name    ?? $projectInfo['clientName']    ?? 'Unknown Client';
            $projectInfo['enquiryTitle']  = $task->enquiry->title               ?? $projectInfo['enquiryTitle']  ?? 'Untitled Project';
            $projectInfo['eventVenue']    = $task->enquiry->venue               ?? $projectInfo['eventVenue']    ?? 'Venue TBC';
            $projectInfo['setupDate']     = $task->enquiry->expected_delivery_date
                ? $task->enquiry->expected_delivery_date->format('Y-m-d')
                : ($projectInfo['setupDate'] ?? 'Date TBC');
            $projectInfo['code']          = $task->enquiry->job_number ?? $task->enquiry->enquiry_number ?? '';
        }

        return [
            'id'                   => $quoteData->id,
            'projectInfo'          => $projectInfo,
            'budgetImported'       => (bool) $quoteData->budget_imported,
            'budgetImportedAt'     => $quoteData->budget_imported_at,
            'budgetUpdatedAt'      => $quoteData->budget_updated_at,
            'budgetVersion'        => $quoteData->budget_version,
            'materials'            => $quoteData->materials       ?? [],
            'labour'               => $quoteData->labour          ?? [],
            'expenses'             => $quoteData->expenses        ?? [],
            'logistics'            => $quoteData->logistics       ?? [],
            'margins'              => $quoteData->margins ?? [
                'materials' => 30,
                'labour'    => 30,
                'expenses'  => 10,
                'logistics' => 20,
                'global'    => 30,
            ],
            'customMargins'        => $quoteData->custom_margins  ?? [],
            'discountAmount'       => (float) ($quoteData->discount_amount  ?? 0),
            'vatPercentage'        => (float) ($quoteData->vat_percentage   ?? 16),
            'vatEnabled'           => (bool)  ($quoteData->vat_enabled      ?? true),
            'totals'               => $quoteData->totals,
            'status'               => $quoteData->status ?? 'draft',
            'viewerSettings'       => $quoteData->viewer_settings ?? [
                'descriptions' => (object)[],
                'overrides'    => (object)[],
            ],
            // Margin governance
            'justification'        => $quoteData->justification,
            'justificationHistory' => $quoteData->justification_history ?? [],
            // Approval fields
            'approvalStatus'       => $quoteData->approval_status ?? $quoteData->status ?? 'draft',
            'enquiryQuoteApproved' => $task && $task->enquiry ? (bool) $task->enquiry->quote_approved : false,
            'approvedBy'           => $quoteData->approved_by,
            'approvalDate'         => $quoteData->approval_date ? $quoteData->approval_date->format('Y-m-d') : null,
            'rejectionReason'      => $quoteData->rejection_reason,
            'approvalComments'     => $quoteData->approval_comments,
            'quoteAmount'          => (float) ($quoteData->quote_amount ?? 0),
            // Excel quote upload (alternative path)
            'quote_mode'              => $quoteData->quote_mode ?? 'built_in',
            'excel_quote_file'        => $quoteData->excel_quote_file,
            'excel_quote_filename'    => $quoteData->excel_quote_filename,
            'excel_quote_amount'      => $quoteData->excel_quote_amount ? (float) $quoteData->excel_quote_amount : null,
            'excel_quote_uploaded_at' => $quoteData->excel_quote_uploaded_at,
            'excel_quote_insights'    => $quoteData->excel_quote_insights,
            'excel_quote_extraction'  => $quoteData->excel_quote_extraction,
            'excel_quote_file_url'    => $quoteData->excel_quote_file ? $this->excelQuoteSignedUrl($quoteData->enquiry_task_id) : null,
            'createdAt'            => $quoteData->created_at,
            'updatedAt'            => $quoteData->updated_at,
        ];
    }

    /**
     * Automatically create a frozen quote snapshot upon approval
     */
    private function createFrozenSnapshot(TaskQuoteData $quoteData, string $label): void
    {
        try {
            $latestVersion = $quoteData->versions()->max('version_number') ?? 0;
            $newVersionNumber = $latestVersion + 1;

            $quoteData->versions()->create([
                'version_number' => $newVersionNumber,
                'label' => $label,
                'data' => $quoteData->fresh()->toArray(),
                'created_by' => auth()->id() ?? 1
            ]);

            $quoteData->recordCustomAction('version_created', [
                'version_number' => $newVersionNumber,
                'label' => $label
            ]);
            
            \Log::info("Automatic frozen quote snapshot created: Version {$newVersionNumber} ({$label})");
        } catch (\Exception $e) {
            \Log::error("Failed to automatically create frozen quote snapshot: " . $e->getMessage());
        }
    }

    /**
     * Upload an Excel quote file as an alternative to the in-system quote builder.
     * Accepts a .xlsx/.xls/.csv file + the agreed quote amount, stores both
     * against TaskQuoteData (mode = excel_upload), and triggers auto-completion.
     */
    public function uploadExcelQuote(Request $request, int $taskId): JsonResponse
    {
        $task = EnquiryTask::findOrFail($taskId);

        $validator = Validator::make($request->all(), [
            'file'           => 'required|file|max:20480|mimes:xlsx,xls,csv,ods',
            'quote_amount'   => 'required|numeric|min:0',
            'revision_notes' => 'nullable|string|max:500',
            'extraction'     => 'nullable|json|max:2000000',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Advisory intelligence (never blocks): detect the workbook's own total,
        // compare the typed amount against it, and compute the implied margin
        // against the enquiry's budget baseline.
        $task->loadMissing('enquiry');
        $insights = $task->enquiry
            ? app(\App\Modules\Projects\Services\QuoteInsightsService::class)->forUpload(
                $task->enquiry,
                (float) $request->input('quote_amount'),
                $request->file('file')->getRealPath()
            )
            : null;
        $extractor = app(\App\Modules\Projects\Services\QuoteWorkbookExtractor::class);
        $reviewedExtraction = $request->filled('extraction')
            ? json_decode((string) $request->input('extraction'), true)
            : null;
        $extraction = is_array($reviewedExtraction)
            ? $extractor->normalizeReview($reviewedExtraction)
            : $extractor->extract($request->file('file')->getRealPath());

        $path = null;
        try {
            $file         = $request->file('file');
            $originalName = $file->getClientOriginalName();
            // Private disk: quote files carry client pricing and must never be
            // reachable through unauthenticated public-storage URLs.
            $path         = $file->store("quote_excel/{$taskId}", 'local');

            // Snapshot the previous Excel revision before overwriting, so every
            // uploaded version is permanently traceable in the QuoteVersion history.
            $existing = TaskQuoteData::where('enquiry_task_id', $taskId)->first();
            if ($existing && $existing->excel_quote_file && $existing->quote_mode === 'excel_upload') {
                $prevVersion = ($existing->versions()->max('version_number') ?? 0) + 1;
                $existing->versions()->create([
                    'version_number' => $prevVersion,
                    'label'          => "Excel Rev {$prevVersion} — {$existing->excel_quote_filename} (replaced " . now()->format('d M Y') . ')',
                    'data'           => [
                        'quote_mode'              => 'excel_upload',
                        'excel_quote_file'        => $existing->excel_quote_file,
                        'excel_quote_filename'    => $existing->excel_quote_filename,
                        'excel_quote_amount'      => (float) $existing->excel_quote_amount,
                        'excel_quote_uploaded_at' => optional($existing->excel_quote_uploaded_at)->toIso8601String(),
                        'excel_quote_uploaded_by' => $existing->excel_quote_uploaded_by,
                        'excel_quote_extraction'  => $existing->excel_quote_extraction,
                        'revision_notes'          => $request->input('revision_notes'),
                    ],
                    'created_by'     => auth()->id(),
                ]);

                // If this revision replaces an approved quote, invalidate the approval
                // so Finance must re-review the new scope before funds are released.
                if (in_array($existing->approval_status, ['approved'])) {
                    $existing->update([
                        'approval_status'   => 'pending',
                        'status'            => 'pending',
                        'approved_by'       => null,
                        'approval_date'     => null,
                        'approval_comments' => null,
                    ]);
                    $task->load('enquiry');
                    $task->enquiry?->update(['client_approved_quote' => null]);

                    $this->invalidateQuoteApproval($task, $existing, 'Excel quote re-uploaded over an approved quote');
                }
            }

            $quoteData = TaskQuoteData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'quote_mode'               => 'excel_upload',
                    'excel_quote_file'         => $path,
                    'excel_quote_filename'     => $originalName,
                    'excel_quote_amount'       => $request->input('quote_amount'),
                    'excel_quote_uploaded_by'  => auth()->id(),
                    'excel_quote_uploaded_at'  => now(),
                    'excel_quote_insights'     => $insights,
                    'excel_quote_extraction'   => $extraction,
                    // Keep quote_amount in sync so downstream approval logic works
                    'quote_amount'             => $request->input('quote_amount'),
                ]
            );

            return response()->json([
                'message'   => 'Excel quote uploaded successfully.',
                'advisories'=> $this->uploadAdvisories($insights),
                'data'      => [
                    'id'                      => $quoteData->id,
                    'quote_mode'              => $quoteData->quote_mode,
                    'excel_quote_filename'    => $quoteData->excel_quote_filename,
                    'excel_quote_amount'      => $quoteData->excel_quote_amount,
                    'excel_quote_uploaded_at' => $quoteData->excel_quote_uploaded_at,
                    'quote_amount'            => $quoteData->quote_amount,
                    'insights'                => $insights,
                    'extraction'              => $extraction,
                    'file_url'                => $this->excelQuoteSignedUrl($taskId),
                ],
            ]);
        } catch (\Exception $e) {
            // The file is written before database persistence. If persistence
            // fails, remove only this newly generated path so retries do not
            // accumulate unreferenced confidential quote workbooks.
            if ($path && \Storage::disk('local')->exists($path)) {
                \Storage::disk('local')->delete($path);
            }
            \Log::error("Excel quote upload failed for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Upload failed. Please try again.'], 500);
        }
    }

    /**
     * Remove a previously uploaded Excel quote and revert the task to built-in mode.
     */
    public function removeExcelQuote(int $taskId): JsonResponse
    {
        $task      = EnquiryTask::findOrFail($taskId);
        $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

        if (!$quoteData || !$quoteData->excel_quote_file) {
            return response()->json(['message' => 'No Excel quote found for this task.'], 404);
        }

        // An approved Excel quote is the financial anchor for downstream gates —
        // it can only be superseded by a new revision, never silently removed.
        if ($quoteData->approval_status === 'approved') {
            return response()->json([
                'message' => 'This Excel quote has been approved and cannot be removed. Upload a new revision instead so Finance can re-review it.'
            ], 422);
        }

        try {
            $path = $quoteData->excel_quote_file;

            // Keep the physical file if any version snapshot still references it,
            // otherwise the audit trail would point at a deleted file.
            $referencedByVersion = $quoteData->versions()
                ->where('data->excel_quote_file', $path)
                ->exists();

            if (!$referencedByVersion) {
                foreach (['local', 'public'] as $disk) {
                    if (\Storage::disk($disk)->exists($path)) {
                        \Storage::disk($disk)->delete($path);
                        break;
                    }
                }
            }

            $quoteData->update([
                'quote_mode'              => 'built_in',
                'excel_quote_file'        => null,
                'excel_quote_filename'    => null,
                'excel_quote_amount'      => null,
                'excel_quote_uploaded_by' => null,
                'excel_quote_uploaded_at' => null,
                'excel_quote_insights'    => null,
                'excel_quote_extraction'  => null,
            ]);

            return response()->json(['message' => 'Excel quote removed. You can now build the quote in-system.']);
        } catch (\Exception $e) {
            \Log::error("Excel quote removal failed for task {$taskId}: " . $e->getMessage());
            return response()->json(['message' => 'Removal failed. Please try again.'], 500);
        }
    }

    /**
     * Inspect an Excel file WITHOUT storing it: detect the workbook's grand
     * total so the frontend can pre-fill the quote amount field. Advisory
     * only — the user can always override the value before uploading.
     */
    public function inspectExcelQuote(Request $request, int $taskId): JsonResponse
    {
        EnquiryTask::findOrFail($taskId);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:20480|mimes:xlsx,xls,csv,ods',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $detected = app(\App\Modules\Projects\Services\QuoteInsightsService::class)
            ->detectWorkbookTotal($request->file('file')->getRealPath());
        $extraction = app(\App\Modules\Projects\Services\QuoteWorkbookExtractor::class)
            ->extract($request->file('file')->getRealPath());

        return response()->json([
            'data' => ['detected_total' => $detected, 'extraction' => $extraction],
        ]);
    }

    /**
     * Delete a single quote version snapshot. Blocked while the quote is
     * approved — version history is the audit trail backing the approval.
     */
    public function deleteVersion(int $taskId, int $versionId): JsonResponse
    {
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

        if (!$quoteData) {
            return response()->json(['message' => 'Quote not found'], 404);
        }

        if ($locked = $this->approvedQuoteLockResponse($quoteData)) {
            return $locked;
        }

        $version = QuoteVersion::where('task_quote_data_id', $quoteData->id)->find($versionId);
        if (!$version) {
            return response()->json(['message' => 'Version not found for this quote'], 404);
        }

        $this->deleteVersionSnapshotFile($quoteData, $version);

        \App\Models\GovernanceAuditLog::create([
            'project_enquiry_id' => $task->project_enquiry_id,
            'user_id' => auth()->id(),
            'gate_type' => 'Quote History Deletion',
            'action_status' => 'authorized',
            'model_type' => QuoteVersion::class,
            'model_id' => $version->id,
            'message' => "Quote version {$version->version_number} ('{$version->label}') deleted",
            'context' => ['version_number' => $version->version_number, 'label' => $version->label],
            'ip_address' => request()->ip(),
        ]);

        $version->delete();

        return response()->json(['message' => 'Version deleted.']);
    }

    /**
     * Clear ALL version snapshots for a quote (the current quote/file is kept).
     * Blocked while the quote is approved.
     */
    public function clearVersions(int $taskId): JsonResponse
    {
        $task = EnquiryTask::findOrFail($taskId);
        $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->first();

        if (!$quoteData) {
            return response()->json(['message' => 'Quote not found'], 404);
        }

        if ($locked = $this->approvedQuoteLockResponse($quoteData)) {
            return $locked;
        }

        $versions = $quoteData->versions()->get();
        foreach ($versions as $version) {
            $this->deleteVersionSnapshotFile($quoteData, $version);
            $version->delete();
        }

        if ($versions->isNotEmpty()) {
            \App\Models\GovernanceAuditLog::create([
                'project_enquiry_id' => $task->project_enquiry_id,
                'user_id' => auth()->id(),
                'gate_type' => 'Quote History Deletion',
                'action_status' => 'authorized',
                'model_type' => TaskQuoteData::class,
                'model_id' => $quoteData->id,
                'message' => "All {$versions->count()} quote version(s) cleared",
                'context' => ['deleted_count' => $versions->count()],
                'ip_address' => request()->ip(),
            ]);
        }

        return response()->json(['message' => "{$versions->count()} version(s) cleared."]);
    }

    /**
     * Remove a version's snapshot file, but only when it is neither the
     * current quote file nor referenced by any other remaining version.
     */
    private function deleteVersionSnapshotFile(TaskQuoteData $quoteData, QuoteVersion $version): void
    {
        $path = $version->data['excel_quote_file'] ?? null;
        if (!$path || $quoteData->excel_quote_file === $path) {
            return;
        }

        $referencedElsewhere = $quoteData->versions()
            ->where('id', '!=', $version->id)
            ->where('data->excel_quote_file', $path)
            ->exists();

        if ($referencedElsewhere) {
            return;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                break;
            }
        }
    }

    /**
     * Stream an Excel quote file (current or a specific version snapshot).
     * Route is protected by the 'signed' middleware — links are minted by
     * excelQuoteSignedUrl() and expire, so files never need a public disk.
     */
    public function downloadExcelQuote(Request $request, int $taskId)
    {
        $quoteData = TaskQuoteData::where('enquiry_task_id', $taskId)->firstOrFail();

        $path     = $quoteData->excel_quote_file;
        $filename = $quoteData->excel_quote_filename;

        if ($versionId = $request->query('version')) {
            $version  = QuoteVersion::where('task_quote_data_id', $quoteData->id)->findOrFail($versionId);
            $path     = $version->data['excel_quote_file'] ?? null;
            $filename = $version->data['excel_quote_filename'] ?? null;
        }

        abort_if(!$path, 404, 'No Excel quote file found.');

        // 'public' fallback covers files uploaded before quotes moved to the private disk.
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->download($path, $filename ?: basename($path));
            }
        }

        abort(404, 'Excel quote file is missing from storage.');
    }

    /**
     * Human-readable, non-blocking advisories derived from upload insights.
     * These inform the uploader and Finance; they never stop the upload.
     */
    private function uploadAdvisories(?array $insights): array
    {
        if (!$insights) {
            return [];
        }

        $advisories = [];

        if ($insights['amount_match'] === 'mismatch') {
            $advisories[] = sprintf(
                'The entered amount (%s) differs from the largest total found in the spreadsheet (%s). Double-check before sending for approval.',
                number_format($insights['declared_amount'], 2),
                number_format($insights['detected_workbook_total'], 2)
            );
        }

        if ($insights['margin_flag'] === 'loss') {
            $advisories[] = sprintf(
                'This amount is below the budget cost baseline (%s excl. VAT vs budget %s) — the project would run at a loss.',
                number_format($insights['net_amount_excl_vat'], 2),
                number_format($insights['budget_cost'], 2)
            );
        } elseif ($insights['margin_flag'] === 'below_watch_floor') {
            $advisories[] = sprintf(
                'Implied margin is %.2f%%, below the %.0f%% watch floor. Finance will see this flag during approval.',
                $insights['implied_margin_pct'],
                $insights['margin_watch_floor_pct']
            );
        }

        return $advisories;
    }

    /**
     * Approval decisions (approve/reject) require the finance quote approval
     * permission. Saving a 'pending' state remains open to any authenticated user.
     */
    private function approvalPermissionDeniedResponse(Request $request): ?JsonResponse
    {
        if (!in_array($request->approval_status, ['approved', 'rejected'], true)) {
            return null;
        }

        $user = $request->user();
        try {
            if ($user && $user->hasPermissionTo(\App\Constants\Permissions::FINANCE_QUOTE_APPROVE)) {
                return null;
            }
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            // Permission not seeded — treat as not granted rather than a 500.
        }

        return response()->json([
            'message' => 'Only users with the finance quote approval permission can approve or reject quotes.'
        ], 403);
    }

    /**
     * An approved quote is the financial anchor for downstream gates and must be
     * immutable. Edits require either a new Excel revision (which invalidates the
     * approval) or Finance reopening the approval first.
     */
    private function approvedQuoteLockResponse(?TaskQuoteData $quoteData): ?JsonResponse
    {
        if ($quoteData && $quoteData->approval_status === 'approved') {
            return response()->json([
                'message' => 'This quote has been approved and is locked. Upload a new revision or ask Finance to reopen the approval before editing.'
            ], 422);
        }

        return null;
    }

    private function excelQuoteSignedUrl(int $taskId, ?int $versionId = null): string
    {
        $params = ['taskId' => $taskId];
        if ($versionId !== null) {
            $params['version'] = $versionId;
        }

        // Relative signing (absolute: false): the signature covers path+query
        // only, so the link works from any browser origin (Vite dev proxy,
        // production host) regardless of APP_URL. Validated by 'signed:relative'.
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'quote.excel.download',
            now()->addMinutes(30),
            $params,
            absolute: false
        );
    }

    /**
     * When a new Excel revision replaces an approved quote, the approval record,
     * the approval task, and Finance's awareness must all be reset together —
     * otherwise the invalidation is invisible and the stale approval can be reused.
     */
    private function invalidateQuoteApproval(EnquiryTask $task, TaskQuoteData $quoteData, string $reason): void
    {
        $enquiry = $task->enquiry;
        if (!$enquiry) {
            return;
        }

        // Reset the approval-of-record so the reopened task cannot be completed
        // against the stale decision (EnquiryWorkflowService checks this table).
        \DB::table('quote_approvals')
            ->where('enquiry_id', $enquiry->id)
            ->update(['approval_status' => 'pending', 'updated_at' => now()]);

        $approvalTask = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->where('type', 'quote_approval')
            ->first();
        if ($approvalTask && $approvalTask->status === 'completed') {
            $approvalTask->update(['status' => 'in_progress']);
        }

        \App\Models\GovernanceAuditLog::create([
            'project_enquiry_id' => $enquiry->id,
            'user_id'            => auth()->id(),
            'gate_type'          => 'financial',
            'action_status'      => 'invalidated',
            'model_type'         => TaskQuoteData::class,
            'model_id'           => $quoteData->id,
            'message'            => "{$reason}; quote approval invalidated pending Finance re-review",
            'context'            => [
                'previous_amount'        => (float) $quoteData->excel_quote_amount,
                'reopened_approval_task' => $approvalTask?->id,
            ],
            'ip_address'         => request()->ip(),
        ]);

        app(\App\Modules\Projects\Services\NotificationService::class)
            ->sendQuoteApprovalInvalidated($enquiry, auth()->user(), $reason);
    }
}
