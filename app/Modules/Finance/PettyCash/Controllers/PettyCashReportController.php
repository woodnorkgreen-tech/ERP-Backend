<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Exports\PettyCashTransactionsExport;
use App\Modules\Finance\PettyCash\Services\PettyCashReportService;
use App\Modules\Finance\PettyCash\Services\FundCustodyService;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The read side of petty cash reporting.
 *
 * PettyCashReportService has existed with seven report generators and no caller
 * of any kind; the frontend meanwhile shipped a ReportsPanel calling
 * `/analytics` and `/reports/projects`, neither of which was routed. Both halves
 * were built, neither was joined. This is the join.
 *
 * Only the two endpoints the panel actually consumes are exposed. The rest of
 * the service stays uncalled rather than being routed speculatively — an
 * endpoint with no consumer is what produced this situation in the first place.
 *
 * Gated on the `viewReports` ability, which is also what guards the activity
 * log, so "may read the audit trail" and "may read the spend reports" stay the
 * same right.
 */
class PettyCashReportController extends Controller
{
    public function __construct(private PettyCashReportService $reports) {}

    public function custody(Request $request, FundCustodyService $custody): JsonResponse
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $start = Carbon::parse($validated['start_date'] ?? now()->startOfMonth())->startOfDay();
        $end = Carbon::parse($validated['end_date'] ?? now())->endOfDay();

        return response()->json(['success' => true, 'data' => $custody->overview($start, $end)]);
    }

    public function topUpCustody(int $id, FundCustodyService $custody): JsonResponse
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);
        return response()->json(['success' => true, 'data' => $custody->topUp($id)]);
    }

    public function custodyStatement(Request $request, FundCustodyService $custody)
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $start = Carbon::parse($validated['start_date'] ?? now()->startOfMonth())->startOfDay();
        $end = Carbon::parse($validated['end_date'] ?? now())->endOfDay();
        $report = $custody->overview($start, $end);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['PETTY CASH FUND CUSTODY STATEMENT']);
            fputcsv($out, ['Period', $report['period']['start'].' to '.$report['period']['end']]);
            foreach ($report['summary'] as $label => $value) fputcsv($out, [str_replace('_', ' ', strtoupper($label)), $value]);
            fputcsv($out, []);
            fputcsv($out, ['Funding batch', 'Date', 'Source', 'Reference', 'Description', 'Received', 'Consumed', 'Remaining', 'Utilization %', 'State']);
            foreach ($report['batches'] as $batch) fputcsv($out, [
                $batch['reference'], $batch['date'], $batch['source'], $batch['transaction_code'], $batch['description'],
                $batch['received'], $batch['consumed'], $batch['remaining'], $batch['utilization_percentage'], $batch['state'],
            ]);
            fclose($out);
        }, 'petty-cash-custody-'.$start->toDateString().'-'.$end->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function topUpStatement(int $id, FundCustodyService $custody)
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);
        $report = $custody->topUp($id);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['TOP-UP CUSTODY STATEMENT', $report['reference']]);
            fputcsv($out, ['Received', $report['received']]);
            fputcsv($out, ['Consumed', $report['consumed']]);
            fputcsv($out, ['Remaining', $report['remaining']]);
            fputcsv($out, []);
            fputcsv($out, ['Payment ID', 'Date', 'Receiver', 'Description', 'Classification', 'Project', 'Requisition ID', 'Amount', 'Transaction cost', 'Total consumed']);
            foreach ($report['payments'] as $payment) fputcsv($out, [
                $payment['disbursement_id'], $payment['date'], $payment['receiver'], $payment['description'], $payment['classification'],
                $payment['project_name'], $payment['requisition_id'], $payment['amount'], $payment['transaction_cost'], $payment['total'],
            ]);
            fclose($out);
        }, strtolower($report['reference']).'-statement.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Spend split by classification and by payment method.
     *
     * The service returns these under `spending_by_*` inside the wider summary
     * report; the client's SpendingAnalytics contract is `by_*` and nothing
     * else in that payload is used here. Mapping at the edge keeps the service
     * shape (shared with the summary report) and the client contract both
     * intact, rather than bending one to the other.
     */
    public function analytics(Request $request): JsonResponse
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);

        $filters = $this->filters($request);

        try {
            $report = $this->reports->generateSummaryReport($filters);

            return response()->json([
                'success' => true,
                'data' => [
                    'by_classification' => $report['spending_by_classification'],
                    'by_payment_method' => $report['spending_by_payment_method'],
                ],
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

    /** Spend per project, ranked, with each project's share of the total. */
    public function projects(Request $request): JsonResponse
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);

        $filters = $this->filters($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->reports->generateProjectReport($filters),
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve project report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * A real spreadsheet, built server-side.
     *
     * `PettyCashTransactionsExport` takes exactly what
     * `PettyCashReportService::generateExportData()` returns, and neither had a
     * caller — so exporting was done in the browser instead, from whatever the
     * `/voucher` endpoint happened to return. That capped the export at one
     * response with no pagination, and wrote tab-separated text under an `.xls`
     * extension. This replaces both problems with the export the module already
     * had the code for.
     */
    public function export(Request $request)
    {
        $this->authorize('viewReports', PettyCashDisbursement::class);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:disbursements,top_ups,summary'],
        ]);

        $type = $validated['type'];
        $report = $this->reports->generateExportData($type, $this->filters($request));

        return Excel::download(
            new PettyCashTransactionsExport($report['headers'], $report['data'], $type),
            sprintf('petty-cash-%s-%s.xlsx', $type, now()->toDateString()),
        );
    }

    /**
     * The same four filter keys `summary` already accepts, so a filter set the
     * user picks once applies identically across every panel on the screen.
     *
     * The period is resolved here rather than left to the services, because the
     * two disagree when it is absent: generateSummaryReport falls back to the
     * current month, generateProjectReport to all time. Both feed the same
     * screen, so that difference would render a classification chart covering
     * this month directly above a project table covering all history, with no
     * indication that the two were measuring different things. Pinning one
     * window for both is what makes the totals comparable.
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'classification' => ['nullable', 'string', 'max:50'],
            'project_name' => ['nullable', 'string', 'max:255'],
        ]);

        $filters = array_filter(
            $validated,
            static fn ($value) => $value !== null && $value !== '',
        );

        // Partial ranges resolve to the open end rather than being dropped: a
        // start with no end means "since then", not "the current month".
        if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            $filters['start_date'] ??= Carbon::parse($filters['end_date'])->startOfMonth()->toDateString();
            $filters['end_date'] ??= Carbon::now()->endOfDay()->toDateString();
        } else {
            $filters['start_date'] = Carbon::now()->startOfMonth()->toDateString();
            $filters['end_date'] = Carbon::now()->endOfMonth()->toDateString();
        }

        return $filters;
    }
}
