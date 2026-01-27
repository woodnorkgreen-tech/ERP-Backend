<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Services\PettyCashReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;
use App\Modules\Finance\PettyCash\Exports\PettyCashTransactionsExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Carbon\Carbon;

class PettyCashReportController extends Controller
{
    protected $reportService;

    public function __construct(PettyCashReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get transaction summary.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);
            $summary = $this->reportService->generateSummaryReport($filters);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve summary',
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
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);
            $analytics = $this->reportService->generateChartData($filters);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export data to Excel or CSV.
     */
    public function export(Request $request)
    {
        try {
            $type = $request->get('type', 'summary');
            $format = $request->get('format', 'excel');
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);
            
            $exportData = $this->reportService->generateExportData($type, $filters);
            $fileName = 'petty-cash-' . $type . '-' . now()->format('Y-m-d') . ($format === 'excel' ? '.xlsx' : '.csv');
            
            $export = new PettyCashTransactionsExport($exportData['headers'], $exportData['data'], $type);
            
            if ($format === 'csv') {
                return Excel::download($export, $fileName, \Maatwebsite\Excel\Excel::CSV);
            }
            
            return Excel::download($export, $fileName);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate PDF report.
     */
    public function report(Request $request)
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'classification', 'project_name']);
            $data = $this->reportService->generateSummaryReport($filters);
            $data['detailed'] = $this->reportService->generateDetailedReport($filters);
            
            $pdf = Pdf::loadView('finance.petty-cash.reports.summary', $data);
            
            return $pdf->download('petty-cash-report-' . now()->format('Y-m-d') . '.pdf');
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate PDF report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get project spending report.
     */
    public function projectReport(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['start_date', 'end_date', 'classification']);
            $report = $this->reportService->generateProjectReport($filters);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve project report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
