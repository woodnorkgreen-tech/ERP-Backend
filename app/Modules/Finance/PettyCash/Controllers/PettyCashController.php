<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use App\Modules\Finance\PettyCash\Imports\PettyCashDisbursementImport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Exception;

class PettyCashController extends Controller
{
    protected $service;
    protected $repository;

    public function __construct(PettyCashService $service, PettyCashRepository $repository)
    {
        $this->service = $service;
        $this->repository = $repository;
    }

    /**
     * Get approved projects list for petty cash forms
     * Proxies to the Projects module logic to ensure consistent data access
     */
    public function getProjects(): JsonResponse
    {
        // Re-use the exact same logic from EnquiryController for consistency
        // Sort by Job Number: Year DESC, Month DESC, Sequence DESC
        $projects = \App\Models\ProjectEnquiry::where('quote_approved', true)
            ->whereNotNull('job_number')
            ->select('id', 'job_number', 'project_id', 'title')
            ->orderByRaw('
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, "-", 3), "-", -1) AS UNSIGNED) DESC,
                CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(job_number, "-", 2), "-", -1) AS UNSIGNED) DESC,
                CAST(SUBSTRING_INDEX(job_number, "-", -1) AS UNSIGNED) DESC
            ')
            ->take(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $projects
        ]);
    }

    /**
     * Display a listing of disbursements.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'status', 'classification', 'payment_method', 'project_name', 
                'creator_id', 'start_date', 'end_date', 'search'
            ]);

            $perPage = $request->get('per_page', 15);
            $disbursements = $this->repository->getDisbursements($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $disbursements->items(),
                'meta' => [
                    'current_page' => $disbursements->currentPage(),
                    'last_page' => $disbursements->lastPage(),
                    'per_page' => $disbursements->perPage(),
                    'total' => $disbursements->total(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursements',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created disbursement.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate the request data
            $validationErrors = $this->service->validateDisbursementData($request->all());
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $result = $this->service->createDisbursement($request->all());

            if (isset($result['success']) && !$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $result['errors'],
                ], 422);
            }

            $disbursement = $result['data'];

            return response()->json([
                'success' => true,
                'message' => 'Disbursement created successfully',
                'data' => $disbursement->load('topUp', 'creator'),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Display the specified disbursement.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $disbursement,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursement',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified disbursement.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            // Check if disbursement can be updated
            if ($disbursement->is_voided) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update voided disbursement',
                ], 400);
            }

            // Validate the request data (pass true for isUpdate and the ID to support partial updates and correct balance checks)
            $validationErrors = $this->service->validateDisbursementData($request->all(), true, $id);
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validationErrors,
                ], 422);
            }

            $updatedDisbursement = $this->service->updateDisbursement($disbursement, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Disbursement updated successfully',
                'data' => $updatedDisbursement->load('topUp', 'creator'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Void the specified disbursement.
     */
    public function void(Request $request, int $id): JsonResponse
    {
        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            // Validate void reason
            $request->validate([
                'void_reason' => 'required|string|max:500',
            ]);

            $this->service->voidDisbursement($disbursement, $request->void_reason);

            return response()->json([
                'success' => true,
                'message' => 'Disbursement voided successfully',
                'data' => $disbursement->fresh()->load('topUp', 'creator', 'voidedBy'),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to void disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete the specified disbursement.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $disbursement = $this->repository->findDisbursement($id);

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found',
                ], 404);
            }

            $this->service->deleteDisbursement($disbursement);

            return response()->json([
                'success' => true,
                'message' => 'Disbursement deleted successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete disbursement',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Delete multiple disbursements.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'exists:petty_cash_disbursements,id'
            ]);

            $result = $this->service->bulkDeleteDisbursements($request->ids);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk deletion',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Clear all petty cash data.
     */
    public function clearAll(): JsonResponse
    {
        try {
            $result = $this->service->clearAllData();

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear petty cash data',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get hierarchical transaction view (top-ups with disbursements).
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'payment_method', 'creator_id', 'start_date', 'end_date', 
                'search', 'status', 'classification', 'show_archived'
            ]);

            $perPage = $request->get('per_page', 15);
            $filters['page'] = $request->get('page', 1);
            $transactions = $this->repository->getFlatTransactions($filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current balance information.
     */
    public function balance(): JsonResponse
    {
        try {
            $balanceInfo = $this->service->getCurrentBalanceInfo();

            return response()->json([
                'success' => true,
                'data' => $balanceInfo,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve balance information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get transaction summary and analytics.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $summary = $this->service->getTransactionSummary($filters);

            return response()->json([
                'success' => true,
                'data' => $summary,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transaction summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get spending analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'start_date', 'end_date', 'classification', 'project_name'
            ]);

            $analytics = $this->service->getSpendingAnalytics($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve spending analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent transactions for dashboard.
     */
    public function recent(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 10);
            $recentTransactions = $this->service->getRecentTransactions($limit);

            return response()->json([
                'success' => true,
                'data' => $recentTransactions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search across all transactions.
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'query' => 'required|string|min:2',
            ]);

            $filters = $request->only([
                'status', 'classification', 'payment_method', 'start_date', 'end_date'
            ]);

            $perPage = $request->get('per_page', 15);
            $results = $this->repository->searchTransactions($request->query, $filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $results->items(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
                'query' => $request->query,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Recalculate balance (admin function).
     */
    public function recalculateBalance(): JsonResponse
    {
        try {
            $result = $this->service->recalculateBalance();

            return response()->json([
                'success' => true,
                'message' => 'Balance recalculated successfully',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload Excel file with disbursement data.
     */
    public function uploadExcel(Request $request): JsonResponse
    {
        try {
            // Validate file upload
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            ], [
                'file.required' => 'Please upload an Excel file',
                'file.mimes' => 'Only Excel files (.xlsx, .xls) are allowed',
                'file.max' => 'File size must be less than 10MB',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $file = $request->file('file');

            // Process the Excel file
            $import = new PettyCashDisbursementImport($this->service);
            ExcelFacade::import($import, $file);

            $results = $import->getResults();

            return response()->json([
                'success' => true,
                'message' => 'Excel file processed successfully',
                'data' => [
                    'total_rows' => $results['total_rows'],
                    'processed_rows' => $results['processed_rows'],
                    'successful_imports' => $results['successful_imports'],
                    'failed_rows' => $results['failed_rows'],
                    'duplicates' => $results['duplicates'],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process Excel file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download Excel template for disbursement upload.
     */
    public function downloadTemplate(): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $headers = [
                'Date', 'Receiver', 'Account', 'Amount', 'Description', 
                'Classification', 'Tax', 'Project Name', 'Job No.', 'Payment Method', 'Transaction Code'
            ];
            
            $tempFile = tempnam(sys_get_temp_dir(), 'petty_cash_template');
            $handle = fopen($tempFile, 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Header row
            fputcsv($handle, $headers);
            
            // Sample data row
            fputcsv($handle, [
                date('Y-m-d'), 
                'John Doe', 
                'Office Supplies', 
                '1500.00', 
                'Stationery for HR department', 
                'Admin', 
                'ETR', 
                '', 
                '', 
                'Cash', 
                ''
            ]);
            
            // Another sample row for a project
            fputcsv($handle, [
                date('Y-m-d'), 
                'Project Team A', 
                'Transport', 
                '5000.00', 
                'Fuel for site visit', 
                'Operations', 
                'ETR', 
                'Building Site X', 
                'WNG-01-2026-001', 
                'Cash', 
                ''
            ]);
            
            fclose($handle);
            
            return response()->download($tempFile, 'petty_cash_disbursements_template.csv', [
                'Content-Type' => 'text/csv',
            ])->deleteFileAfterSend(true);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate template',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive a transaction.
     */
    public function archive(Request $request, int $id): JsonResponse
    {
        try {
            $type = $request->get('type', 'disbursement');
            $userId = auth()->id();
            
            if ($type === 'disbursement') {
                $disbursement = $this->repository->findDisbursement($id);
                if (!$disbursement) {
                    return response()->json(['success' => false, 'message' => 'Disbursement not found'], 404);
                }
                $this->repository->archiveDisbursement($disbursement, $userId);
            } else {
                $topUp = $this->repository->findTopUp($id);
                if (!$topUp) {
                    return response()->json(['success' => false, 'message' => 'Top-up not found'], 404);
                }
                $this->repository->archiveTopUp($topUp, $userId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transaction archived successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive transaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk archive transactions.
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        try {
            $ids = $request->get('ids', []);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
            }

            $userId = auth()->id();
            $count = $this->repository->bulkArchiveDisbursements($ids, $userId);

            return response()->json([
                'success' => true,
                'message' => "{$count} transactions archived successfully",
                'data' => ['count' => $count]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk archive transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Archive a top-up and all its disbursements.
     */
    public function archiveGroup(Request $request, int $id): JsonResponse
    {
        try {
            $userId = auth()->id();
            $this->repository->archiveGroup($id, $userId);

            return response()->json([
                'success' => true,
                'message' => 'Top-up and all related disbursements archived successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk archive multiple groups.
     */
    public function bulkArchiveGroups(Request $request): JsonResponse
    {
        try {
            $ids = $request->get('ids', []);
            if (empty($ids)) {
                return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
            }

            $userId = auth()->id();
            $count = $this->repository->bulkArchiveGroups($ids, $userId);

            return response()->json([
                'success' => true,
                'message' => "{$count} groups archived successfully",
                'data' => ['count' => $count]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk archive groups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}