<?php

namespace App\Http\Controllers;

use App\Models\TaskQuoteData;
use App\Models\TaskBudgetData;
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

            return response()->json([
                'data' => $quoteData->toArray(),
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
                'data' => $quoteData->fresh(),
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
            $task = EnquiryTask::with('enquiry')->find($taskId);

            if (!$task) {
                return response()->json([
                    'message' => 'Quote task not found'
                ], 404);
            }

            // Find budget task for the same enquiry
            $budgetTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                ->where('type', 'budget')
                ->first();

            if (!$budgetTask) {
                return response()->json([
                    'message' => 'Budget task not found for this enquiry'
                ], 404);
            }

            // Check if budget task is completed
            // if ($budgetTask->status !== 'completed') {
            //     return response()->json([
            //         'message' => 'Budget task must be completed before importing data into quote'
            //     ], 409); // 409 Conflict - indicates the request conflicts with current state
            // }

            // Get budget data
            $budgetData = TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->first();

            if (!$budgetData) {
                return response()->json([
                    'message' => 'No budget data found'
                ], 404);
            }

            // Transform budget data to quote format
            $quoteData = $this->transformBudgetToQuote($budgetData);

            // Create or update quote with imported data
            $quote = TaskQuoteData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $budgetData->project_info,
                    'budget_imported' => true,
                    'materials' => $quoteData['materials'],
                    'labour' => $quoteData['labour'],
                    'expenses' => $quoteData['expenses'],
                    'logistics' => $quoteData['logistics'],
                    'margins' => [
                        'materials' => 20,
                        'labour' => 15,
                        'expenses' => 10,
                        'logistics' => 15
                    ],
                    'discount_amount' => 0,
                    'vat_percentage' => 16,
                    'vat_enabled' => true,
                    'totals' => $quoteData['totals'],
                    'status' => 'draft',
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'data' => $quote->fresh(),
                'message' => 'Budget data imported successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import budget data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform budget data to quote format
     */
    private function transformBudgetToQuote(TaskBudgetData $budgetData): array
    {
        $materials = [];
        $labour = [];
        $expenses = [];
        $logistics = [];

        // Transform materials with margin structure
        if ($budgetData->materials_data) {
            foreach ($budgetData->materials_data as $element) {
                $elementMaterials = [];
                foreach ($element['materials'] as $material) {
                    $elementMaterials[] = [
                        'id' => $material['id'],
                        'description' => $material['description'],
                        'unitOfMeasurement' => $material['unitOfMeasurement'],
                        'quantity' => $material['quantity'],
                        'unitPrice' => $material['unitPrice'] ?? 0,
                        'totalPrice' => $material['totalPrice'] ?? 0,
                        'isAddition' => $material['isAddition'] ?? false,
                        'marginPercentage' => 20, // Default margin
                        'marginAmount' => ($material['totalPrice'] ?? 0) * 0.2,
                        'finalPrice' => ($material['totalPrice'] ?? 0) * 1.2
                    ];
                }

                $materials[] = [
                    'id' => $element['id'],
                    'templateId' => $element['templateId'] ?? null,
                    'name' => $element['name'],
                    'materials' => $elementMaterials,
                    'baseTotal' => array_sum(array_column($elementMaterials, 'totalPrice')),
                    'marginPercentage' => 20,
                    'marginAmount' => array_sum(array_column($elementMaterials, 'marginAmount')),
                    'finalTotal' => array_sum(array_column($elementMaterials, 'finalPrice'))
                ];
            }
        }

        // Transform labour (no individual margins)
        if ($budgetData->labour_data) {
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
            $expenses = array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'description' => $item['description'],
                    'category' => $item['category'],
                    'amount' => $item['amount'],
                    'isAddition' => $item['isAddition'] ?? false,
                    'marginPercentage' => 10,
                    'marginAmount' => $item['amount'] * 0.1,
                    'finalPrice' => $item['amount'] * 1.1
                ];
            }, $budgetData->expenses_data);
        }

        // Transform logistics with margins
        if ($budgetData->logistics_data) {
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
                    'marginPercentage' => 15,
                    'marginAmount' => $item['amount'] * 0.15,
                    'finalPrice' => $item['amount'] * 1.15
                ];
            }, $budgetData->logistics_data);
        }

        // Calculate totals
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
            'overallMarginPercentage' => $subtotal > 0 ? round(($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin) / ($materialsBase + $labourBase + $expensesBase + $logisticsBase) * 100, 2) : 0
        ];

        return [
            'materials' => $materials,
            'labour' => $labour,
            'expenses' => $expenses,
            'logistics' => $logistics,
            'totals' => $totals
        ];
    }

    /**
     * Get default quote structure
     */
    private function getDefaultQuoteStructure(int $taskId): array
    {
        $task = EnquiryTask::with('enquiry')->find($taskId);

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
                'labour' => 15,
                'expenses' => 10,
                'logistics' => 15
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
}
