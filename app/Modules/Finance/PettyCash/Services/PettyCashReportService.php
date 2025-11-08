<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Repositories\PettyCashRepository;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class PettyCashReportService
{
    protected $repository;

    public function __construct(PettyCashRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Generate comprehensive summary report.
     */
    public function generateSummaryReport(array $filters = []): array
    {
        // Set default date range if not provided
        if (empty($filters['start_date'])) {
            $filters['start_date'] = Carbon::now()->startOfMonth();
        }
        if (empty($filters['end_date'])) {
            $filters['end_date'] = Carbon::now()->endOfMonth();
        }

        $summary = $this->repository->getTransactionSummary($filters);
        $spendingByClassification = $this->repository->getSpendingByClassification($filters);
        $spendingByPaymentMethod = $this->repository->getSpendingByPaymentMethod($filters);
        
        // Calculate percentages for classifications
        $totalSpending = $summary['total_disbursements'];
        $classificationData = $spendingByClassification->map(function ($item) use ($totalSpending) {
            return [
                'classification' => $item->classification,
                'total_amount' => (float) $item->total_amount,
                'transaction_count' => $item->transaction_count,
                'percentage' => $totalSpending > 0 ? round(($item->total_amount / $totalSpending) * 100, 2) : 0,
            ];
        });

        // Calculate percentages for payment methods
        $paymentMethodData = $spendingByPaymentMethod->map(function ($item) use ($totalSpending) {
            return [
                'payment_method' => $item->payment_method,
                'total_amount' => (float) $item->total_amount,
                'transaction_count' => $item->transaction_count,
                'percentage' => $totalSpending > 0 ? round(($item->total_amount / $totalSpending) * 100, 2) : 0,
            ];
        });

        return [
            'period' => [
                'start_date' => $filters['start_date'],
                'end_date' => $filters['end_date'],
            ],
            'summary' => $summary,
            'spending_by_classification' => $classificationData,
            'spending_by_payment_method' => $paymentMethodData,
            'generated_at' => now(),
        ];
    }

    /**
     * Generate detailed transaction report.
     */
    public function generateDetailedReport(array $filters = []): array
    {
        $disbursements = $this->repository->getDisbursements($filters, 1000); // Large limit for reports
        $topUps = $this->repository->getTopUps($filters, 1000);

        return [
            'filters' => $filters,
            'disbursements' => [
                'data' => $disbursements->items(),
                'total' => $disbursements->total(),
                'summary' => [
                    'total_amount' => $disbursements->sum('amount'),
                    'count' => $disbursements->count(),
                ],
            ],
            'top_ups' => [
                'data' => $topUps->items(),
                'total' => $topUps->total(),
                'summary' => [
                    'total_amount' => $topUps->sum('amount'),
                    'count' => $topUps->count(),
                ],
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * Generate project-wise spending report.
     */
    public function generateProjectReport(array $filters = []): array
    {
        $query = DB::table('petty_cash_disbursements')
            ->select('project_name', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as transaction_count'))
            ->where('status', 'active')
            ->whereNotNull('project_name')
            ->where('project_name', '!=', '')
            ->groupBy('project_name')
            ->orderBy('total_amount', 'desc');

        // Apply date filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('created_at', [$filters['start_date'], $filters['end_date']]);
        }

        // Apply classification filter
        if (!empty($filters['classification'])) {
            $query->where('classification', $filters['classification']);
        }

        $projectData = $query->get();
        $totalAmount = $projectData->sum('total_amount');

        $projects = $projectData->map(function ($item) use ($totalAmount) {
            return [
                'project_name' => $item->project_name,
                'total_amount' => (float) $item->total_amount,
                'transaction_count' => $item->transaction_count,
                'percentage' => $totalAmount > 0 ? round(($item->total_amount / $totalAmount) * 100, 2) : 0,
            ];
        });

        return [
            'filters' => $filters,
            'projects' => $projects,
            'summary' => [
                'total_projects' => $projects->count(),
                'total_amount' => (float) $totalAmount,
                'total_transactions' => $projectData->sum('transaction_count'),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * Generate monthly trend report.
     */
    public function generateMonthlyTrendReport(int $months = 12): array
    {
        $startDate = Carbon::now()->subMonths($months)->startOfMonth();
        $endDate = Carbon::now()->endOfMonth();

        // Get monthly disbursements
        $monthlyDisbursements = DB::table('petty_cash_disbursements')
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count')
            )
            ->where('status', 'active')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        // Get monthly top-ups
        $monthlyTopUps = DB::table('petty_cash_top_ups')
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(*) as transaction_count')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        // Create monthly data array
        $monthlyData = [];
        $current = $startDate->copy();
        
        while ($current <= $endDate) {
            $year = $current->year;
            $month = $current->month;
            
            $disbursement = $monthlyDisbursements->firstWhere(function ($item) use ($year, $month) {
                return $item->year == $year && $item->month == $month;
            });
            
            $topUp = $monthlyTopUps->firstWhere(function ($item) use ($year, $month) {
                return $item->year == $year && $item->month == $month;
            });
            
            $monthlyData[] = [
                'year' => $year,
                'month' => $month,
                'month_name' => $current->format('M Y'),
                'disbursements' => [
                    'amount' => $disbursement ? (float) $disbursement->total_amount : 0,
                    'count' => $disbursement ? $disbursement->transaction_count : 0,
                ],
                'top_ups' => [
                    'amount' => $topUp ? (float) $topUp->total_amount : 0,
                    'count' => $topUp ? $topUp->transaction_count : 0,
                ],
                'net_flow' => ($topUp ? (float) $topUp->total_amount : 0) - ($disbursement ? (float) $disbursement->total_amount : 0),
            ];
            
            $current->addMonth();
        }

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'months' => $months,
            ],
            'monthly_data' => $monthlyData,
            'summary' => [
                'total_disbursements' => array_sum(array_column($monthlyData, 'disbursements.amount')),
                'total_top_ups' => array_sum(array_column($monthlyData, 'top_ups.amount')),
                'net_flow' => array_sum(array_column($monthlyData, 'net_flow')),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * Generate chart data for spending distribution.
     */
    public function generateChartData(array $filters = []): array
    {
        $spendingByClassification = $this->repository->getSpendingByClassification($filters);
        $spendingByPaymentMethod = $this->repository->getSpendingByPaymentMethod($filters);

        return [
            'classification_pie' => [
                'labels' => $spendingByClassification->pluck('classification')->toArray(),
                'data' => $spendingByClassification->pluck('total_amount')->toArray(),
                'backgroundColor' => ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'],
            ],
            'payment_method_doughnut' => [
                'labels' => $spendingByPaymentMethod->pluck('payment_method')->toArray(),
                'data' => $spendingByPaymentMethod->pluck('total_amount')->toArray(),
                'backgroundColor' => ['#FF9F40', '#FF6384', '#4BC0C0', '#9966FF'],
            ],
        ];
    }

    /**
     * Generate export data for Excel/CSV.
     */
    public function generateExportData(string $type, array $filters = []): array
    {
        switch ($type) {
            case 'disbursements':
                return $this->generateDisbursementExportData($filters);
            case 'top_ups':
                return $this->generateTopUpExportData($filters);
            case 'summary':
                return $this->generateSummaryExportData($filters);
            default:
                throw new Exception('Invalid export type specified.');
        }
    }

    /**
     * Calculate totals, averages, and transaction counts.
     */
    public function calculateStatistics(array $filters = []): array
    {
        $summary = $this->repository->getTransactionSummary($filters);
        $currentBalance = $this->repository->getCurrentBalance();

        return [
            'totals' => [
                'top_ups' => $summary['total_top_ups'],
                'disbursements' => $summary['total_disbursements'],
                'net_balance' => $summary['net_balance'],
                'current_balance' => $currentBalance->getCurrentBalance(),
            ],
            'averages' => [
                'top_up' => $summary['average_top_up'],
                'disbursement' => $summary['average_disbursement'],
            ],
            'counts' => [
                'top_ups' => $summary['top_up_count'],
                'disbursements' => $summary['disbursement_count'],
                'total_transactions' => $summary['top_up_count'] + $summary['disbursement_count'],
            ],
            'balance_status' => [
                'current' => $currentBalance->getCurrentBalance(),
                'status' => $currentBalance->status,
                'is_low' => $currentBalance->isLow(),
                'is_critical' => $currentBalance->isCritical(),
            ],
        ];
    }

    /**
     * Generate disbursement export data.
     */
    private function generateDisbursementExportData(array $filters): array
    {
        $disbursements = $this->repository->getDisbursements($filters, 10000);

        return [
            'headers' => [
                'ID', 'Date', 'Receiver', 'Account', 'Amount', 'Description', 
                'Project', 'Classification', 'Payment Method', 'Transaction Code', 
                'Status', 'Created By', 'Voided By', 'Void Reason'
            ],
            'data' => $disbursements->map(function ($disbursement) {
                return [
                    $disbursement->id,
                    $disbursement->created_at->format('Y-m-d H:i:s'),
                    $disbursement->receiver,
                    $disbursement->account,
                    $disbursement->amount,
                    $disbursement->description,
                    $disbursement->project_name,
                    $disbursement->classification,
                    $disbursement->payment_method,
                    $disbursement->transaction_code,
                    $disbursement->status,
                    $disbursement->creator->name ?? '',
                    $disbursement->voidedBy->name ?? '',
                    $disbursement->void_reason,
                ];
            })->toArray(),
        ];
    }

    /**
     * Generate top-up export data.
     */
    private function generateTopUpExportData(array $filters): array
    {
        $topUps = $this->repository->getTopUps($filters, 10000);

        return [
            'headers' => [
                'ID', 'Date', 'Amount', 'Payment Method', 'Transaction Code', 
                'Description', 'Created By', 'Remaining Balance'
            ],
            'data' => $topUps->map(function ($topUp) {
                return [
                    $topUp->id,
                    $topUp->created_at->format('Y-m-d H:i:s'),
                    $topUp->amount,
                    $topUp->payment_method,
                    $topUp->transaction_code,
                    $topUp->description,
                    $topUp->creator->name ?? '',
                    $topUp->remaining_balance,
                ];
            })->toArray(),
        ];
    }

    /**
     * Generate summary export data.
     */
    private function generateSummaryExportData(array $filters): array
    {
        $summary = $this->generateSummaryReport($filters);

        return [
            'headers' => ['Metric', 'Value'],
            'data' => [
                ['Total Top-ups', $summary['summary']['total_top_ups']],
                ['Total Disbursements', $summary['summary']['total_disbursements']],
                ['Net Balance', $summary['summary']['net_balance']],
                ['Top-up Count', $summary['summary']['top_up_count']],
                ['Disbursement Count', $summary['summary']['disbursement_count']],
                ['Average Top-up', $summary['summary']['average_top_up']],
                ['Average Disbursement', $summary['summary']['average_disbursement']],
            ],
        ];
    }
}