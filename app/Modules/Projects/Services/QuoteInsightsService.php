<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\TaskBudgetData;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Advisory intelligence for Excel-uploaded quotes. Everything here is a
 * signal, never a gate: the upload flow stays "any file + typed amount".
 *
 * Signals produced:
 *  - detected_workbook_total: best-effort grand total found inside the file
 *  - amount_match: does the typed amount agree with the detected total
 *  - budget comparison: implied margin of the typed amount against the
 *    enquiry's budget cost baseline (budget_summary.grandTotal)
 *  - mobilization context: the 70% deposit the finance gate will expect
 */
class QuoteInsightsService
{
    /** Relative tolerance when matching the typed amount to the detected total */
    private const AMOUNT_MATCH_TOLERANCE = 0.01;

    /** Indicative margin floor below which a quote is flagged for scrutiny */
    private const MARGIN_WATCH_FLOOR_PCT = 15.0;

    private const MOBILIZATION_PERCENTAGE = 70;

    /**
     * Full insight bundle computed at upload time.
     */
    public function forUpload(ProjectEnquiry $enquiry, float $declaredAmount, ?string $workbookPath): array
    {
        $detected = $workbookPath ? $this->detectWorkbookTotal($workbookPath) : null;

        $amountMatch = 'unknown';
        if ($detected !== null && $detected > 0) {
            $withinTolerance = abs($declaredAmount - $detected) <= max($detected * self::AMOUNT_MATCH_TOLERANCE, 1.0);
            $amountMatch = $withinTolerance ? 'matched' : 'mismatch';
        }

        return array_merge(
            [
                'declared_amount'         => round($declaredAmount, 2),
                'detected_workbook_total' => $detected,
                'amount_match'            => $amountMatch,
                'computed_at'             => now()->toISOString(),
            ],
            $this->budgetComparison($enquiry, $declaredAmount)
        );
    }

    /**
     * Budget-vs-quote context, safe to recompute at approval time (the budget
     * may have changed since upload).
     */
    public function budgetComparison(ProjectEnquiry $enquiry, float $quoteAmount): array
    {
        $budget = $this->budgetBaseline($enquiry);
        $budgetCost = $budget['cost'];

        // The typed amount is VAT-inclusive by convention; compare margins on
        // the net figure so the signal is honest.
        $netAmount = round($quoteAmount / 1.16, 2);

        $marginPct = null;
        $marginFlag = 'no_budget';
        if ($budgetCost !== null && $budgetCost > 0) {
            $marginPct = round(($netAmount - $budgetCost) / $budgetCost * 100, 2);
            $marginFlag = match (true) {
                $marginPct < 0                             => 'loss',
                $marginPct < self::MARGIN_WATCH_FLOOR_PCT  => 'below_watch_floor',
                default                                    => 'healthy',
            };
        }

        return [
            'budget_cost'                 => $budgetCost,
            'budget_status'               => $budget['status'],
            'net_amount_excl_vat'         => $netAmount,
            'implied_margin_pct'          => $marginPct,
            'margin_flag'                 => $marginFlag,
            'margin_watch_floor_pct'      => self::MARGIN_WATCH_FLOOR_PCT,
            'mobilization_threshold_amount' => round($quoteAmount * (self::MOBILIZATION_PERCENTAGE / 100), 2),
        ];
    }

    /**
     * Best-effort grand-total detection inside any workbook: collect numeric
     * values on rows whose first text cell mentions "total" and take the
     * largest (the grand total is normally the biggest "total" line).
     * Returns null when nothing plausible is found — never throws.
     */
    public function detectWorkbookTotal(string $filePath): ?float
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            return null;
        }

        $candidates = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestRow = min($sheet->getHighestDataRow(), 1000);
            $highestCol = min(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
                26
            );

            for ($row = 1; $row <= $highestRow; $row++) {
                $rowHasTotalLabel = false;
                $rowNumbers = [];

                for ($col = 1; $col <= $highestCol; $col++) {
                    try {
                        $value = $sheet->getCell([$col, $row])->getCalculatedValue();
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if (is_string($value) && $value !== '') {
                        if (stripos($value, 'total') !== false) {
                            $rowHasTotalLabel = true;
                        }
                    } elseif (is_numeric($value) && (float) $value > 0) {
                        $rowNumbers[] = (float) $value;
                    }
                }

                if ($rowHasTotalLabel && $rowNumbers !== []) {
                    $candidates[] = max($rowNumbers);
                }
            }
        }

        return $candidates === [] ? null : round(max($candidates), 2);
    }

    /**
     * The enquiry's budget cost baseline: budget_summary.grandTotal from the
     * budget task's data, preferring an approved budget over drafts.
     *
     * @return array{cost: float|null, status: string|null}
     */
    private function budgetBaseline(ProjectEnquiry $enquiry): array
    {
        $budgetData = TaskBudgetData::whereHas('enquiry_task', function ($query) use ($enquiry) {
                $query->where('project_enquiry_id', $enquiry->id)
                    ->where('type', 'budget');
            })
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->first();

        $grandTotal = $budgetData->budget_summary['grandTotal'] ?? null;

        return [
            'cost'   => is_numeric($grandTotal) ? round((float) $grandTotal, 2) : null,
            'status' => $budgetData?->status,
        ];
    }
}
