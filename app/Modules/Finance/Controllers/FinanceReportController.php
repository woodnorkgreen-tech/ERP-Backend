<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\ProfitAndLossService;
use App\Modules\Finance\Services\ReceivablesAgeingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portfolio-wide Finance reports that read the ledger a different way than
 * JournalEntryController's trial balance — grouped by what a period earned
 * and spent, and (see receivablesAgeing) by what is still owed, rather than
 * by account.
 *
 * Guarded by `finance.reports.view` throughout, the same permission every
 * other Finance report endpoint uses (JournalEntryController,
 * TaxScheduleController) — deliberately not a new permission constant, which
 * would need its own seeding and role grant (see the phantom-permission risk
 * noted in docs/general-ledger-plan.md).
 */
class FinanceReportController extends Controller
{
    public function __construct(
        private ProfitAndLossService $profitAndLoss,
        private ReceivablesAgeingService $receivablesAgeingService,
    ) {}

    /**
     * Revenue, Cost of Sales, gross profit, overheads and net profit for a
     * period — management accounts, not yet the statutory Stage 7 report:
     * see the payload's own `coverage` for what is still missing (mainly
     * depreciation and opening balances/equity).
     */
    public function profitAndLoss(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorise($request);

        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $this->profitAndLoss->summary($filters['from'], $filters['to']);

        if (($filters['format'] ?? 'json') === 'csv') {
            $rows = [];
            foreach ($data['sections'] as $section => $accounts) {
                foreach ($accounts as $account) {
                    $rows[] = [
                        ucfirst(str_replace('_', ' ', $section)),
                        $account['code'],
                        $account['name'],
                        $account['amount'],
                    ];
                }
            }

            return $this->csv(
                "profit-and-loss-{$filters['from']}-to-{$filters['to']}.csv",
                ['Section', 'Account code', 'Account name', 'Amount'],
                $rows,
            );
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * Issued invoices still owed, bucketed by days overdue.
     *
     * Per-invoice grain, matching EnquiryController::projectInvoices()'s
     * existing grain — a client rollup is a natural fast-follow, not built
     * here. `bucket` narrows the row list to one bucket without a second
     * request; omit it for every outstanding invoice.
     */
    public function receivablesAgeing(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorise($request);

        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
            'bucket' => ['nullable', 'string', 'in:current,1_30,31_60,61_90,90_plus'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $this->receivablesAgeingService->summary($filters['as_of'] ?? null, $filters['bucket'] ?? null);

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv(
                "receivables-ageing-{$data['as_of']}.csv",
                ['Invoice number', 'Job number', 'Client', 'Due date', 'Days overdue', 'Bucket',
                    'Total amount', 'Paid amount', 'Balance'],
                array_map(fn (array $row) => [
                    $row['invoice_number'], $row['job_number'], $row['client_name'], $row['due_date'],
                    $row['days_overdue'], $row['bucket'], $row['total_amount'], $row['paid_amount'], $row['balance'],
                ], $data['rows']),
            );
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    private function authorise(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);
    }

    /**
     * Streamed so a long period does not build the whole file in memory, and
     * BOM-prefixed so Excel opens it correctly — matching
     * TaxScheduleController's csv() helper, which this deliberately mirrors
     * rather than diverges from.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
