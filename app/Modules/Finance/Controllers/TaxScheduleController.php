<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\TaxScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The KRA-facing read surface: what to claim, what is about to be lost, and
 * what must be remitted.
 *
 * Read-only, and download-shaped. Finance does not file from a screen — they
 * file from a schedule they can attach to a return and keep for the six years
 * KRA may come back over — so every endpoint answers as JSON for the UI and as
 * CSV for the filing pack, off the same service call. Two renderings of one
 * computation, never two computations.
 *
 * Guarded by `finance.reports.view`, the same permission the ledger endpoints
 * use. These schedules carry supplier KRA PINs, which is exactly the kind of
 * third-party tax identity that should not be browsable by whoever can see a
 * project's costs.
 */
class TaxScheduleController extends Controller
{
    public function __construct(private TaxScheduleService $schedules) {}

    /** Input VAT claimable in a period — the purchases side of the VAT return. */
    public function vatInput(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorise($request);

        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $this->schedules->vatInputSchedule($filters['from'], $filters['to']);

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv(
                "vat-input-schedule-{$filters['from']}-to-{$filters['to']}.csv",
                ['Ref', 'Tax point', 'Supplier', 'KRA PIN', 'Supplier invoice', 'eTIMS invoice',
                    'Treatment', 'Rate %', 'Net', 'Input VAT', 'Job', 'Status', 'Claim deadline'],
                array_map(fn (array $row) => [
                    $row['ref'], $row['tax_point_date'], $row['supplier_name'], $row['supplier_pin'],
                    $row['supplier_invoice_no'], $row['etims_invoice_no'], $row['treatment_code'],
                    $row['rate_percent'], $row['net_amount'], $row['vat_amount'], $row['job_number'],
                    $row['claim_status'], $row['claim_deadline'],
                ], $data['rows']),
            );
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /**
     * Recoverable VAT with no eTIMS reference — input tax WNG is about to lose.
     */
    public function etimsGap(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorise($request);

        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $this->schedules->etimsGap($filters['as_of'] ?? null);

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv(
                "etims-gap-{$data['as_of']}.csv",
                ['Ref', 'Tax point', 'Supplier', 'KRA PIN', 'Supplier invoice', 'Input VAT',
                    'Missing', 'Days to deadline', 'Claim deadline', 'Status', 'Job'],
                array_map(fn (array $row) => [
                    $row['ref'], $row['tax_point_date'], $row['supplier_name'], $row['supplier_pin'],
                    $row['supplier_invoice_no'], $row['vat_amount'], implode(' + ', $row['missing']),
                    $row['days_to_deadline'], $row['claim_deadline'], $row['claim_status'], $row['job_number'],
                ], $data['rows']),
            );
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /** Withholding deducted in a month, by payee — the WHT remittance return. */
    public function wht(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorise($request);

        $filters = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $data = $this->schedules->whtSchedule((int) $filters['year'], (int) $filters['month']);

        if (($filters['format'] ?? 'json') === 'csv') {
            return $this->csv(
                sprintf('wht-schedule-%04d-%02d.csv', $filters['year'], $filters['month']),
                ['Payee', 'KRA PIN', 'Category', 'Rate %', 'Gross subject to WHT', 'WHT withheld',
                    'Payments', 'Under-withheld', 'Note'],
                array_map(fn (array $row) => [
                    $row['payee_name'], $row['supplier_pin'], $row['wht_category_code'],
                    $row['rate_percent'], $row['net_amount'], $row['wht_amount'],
                    $row['payment_count'], $row['aggregation_shortfall'], $row['aggregation_note'],
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
     * BOM-prefixed so Excel opens supplier names with accents correctly rather
     * than mangling them — the file's whole purpose is to be opened in Excel by
     * an accountant.
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
