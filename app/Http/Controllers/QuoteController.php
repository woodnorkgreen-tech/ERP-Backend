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

            // Load client data for existing quotes
            $task = EnquiryTask::with('enquiry.client')->find($taskId);
            if ($task && $task->enquiry && $task->enquiry->client) {
                $quoteArray = $quoteData->toArray();
                $quoteArray['projectInfo']['clientName'] = $task->enquiry->client->full_name ?? 'Unknown Client';
                return response()->json([
                    'data' => $quoteArray,
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

             \Log::info("Quote data imported successfully for task: {$taskId}");

             return response()->json([
                 'data' => $quote->fresh(),
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

        // Add approved budget additions to materials, labour, expenses, logistics
        if ($approvedAdditions) {
            \Log::info("Including " . $approvedAdditions->count() . " approved budget additions");

            foreach ($approvedAdditions as $addition) {
                // Add materials from approved additions
                if ($addition->materials) {
                    foreach ($addition->materials as $material) {
                        // Calculate totals for this material
                        $quantity = $material['quantity'] ?? 0;
                        $unitPrice = $material['unitPrice'] ?? 0;
                        $totalPrice = $quantity * $unitPrice;
                        $marginAmount = $totalPrice * 0.2; // 20% margin
                        $finalPrice = $totalPrice + $marginAmount;

                        \Log::info("Processing addition material", [
                            'addition_id' => $addition->id,
                            'material_id' => $material['id'],
                            'quantity' => $quantity,
                            'unitPrice' => $unitPrice,
                            'totalPrice' => $totalPrice,
                            'marginAmount' => $marginAmount,
                            'finalPrice' => $finalPrice
                        ]);

                        $materials[] = [
                            'id' => 'addition_' . $addition->id . '_material_' . $material['id'],
                            'templateId' => null,
                            'name' => 'Budget Addition: ' . $addition->title,
                            'materials' => [
                                [
                                    'id' => $material['id'],
                                    'description' => $material['description'],
                                    'unitOfMeasurement' => $material['unitOfMeasurement'],
                                    'quantity' => $quantity,
                                    'unitPrice' => $unitPrice,
                                    'totalPrice' => $totalPrice,
                                    'isAddition' => true,
                                    'marginPercentage' => 20,
                                    'marginAmount' => $marginAmount,
                                    'finalPrice' => $finalPrice
                                ]
                            ],
                            'baseTotal' => $totalPrice,
                            'marginPercentage' => 20,
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
                        $marginAmount = $amount * 0.1; // 10% margin
                        $finalPrice = $amount + $marginAmount;
                        return [
                            'id' => $item['id'],
                            'description' => $item['description'],
                            'category' => $item['category'] ?? 'Additional Expense',
                            'amount' => $amount,
                            'isAddition' => true,
                            'marginPercentage' => 10,
                            'marginAmount' => $marginAmount,
                            'finalPrice' => $finalPrice
                        ];
                    }, $addition->expenses));
                }

                // Add logistics from approved additions
                if ($addition->logistics) {
                    $logistics = array_merge($logistics, array_map(function ($item) {
                        $amount = $item['amount'] ?? (($item['quantity'] ?? 1) * ($item['unitRate'] ?? 0));
                        $marginAmount = $amount * 0.15; // 15% margin
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
                            'marginPercentage' => 15,
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
                    'marginPercentage' => 10,
                    'marginAmount' => $item['amount'] * 0.1,
                    'finalPrice' => $item['amount'] * 1.1
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
                    'marginPercentage' => 15,
                    'marginAmount' => $item['amount'] * 0.15,
                    'finalPrice' => $item['amount'] * 1.15
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
            'overallMarginPercentage' => $subtotal > 0 ? round(($materialsMargin + $labourMargin + $expensesMargin + $logisticsMargin) / ($materialsBase + $labourBase + $expensesBase + $logisticsBase) * 100, 2) : 0
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
