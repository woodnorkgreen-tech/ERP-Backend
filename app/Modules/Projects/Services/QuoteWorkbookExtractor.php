<?php

namespace App\Modules\Projects\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;

/**
 * Converts a client quote workbook into a conservative operational proposal.
 * The workbook remains the legal/audit source; this extraction is reviewable
 * planning data. Ambiguous text is retained as a custom material and is never
 * automatically linked to Stores inventory.
 */
class QuoteWorkbookExtractor
{
    private const HEADERS = [
        'element' => ['element', 'section', 'category', 'work item', 'scope', 'group'],
        'description' => ['description', 'particular', 'particulars', 'item', 'material', 'product', 'details'],
        'quantity' => ['qty', 'quantity', 'quant'],
        'unit' => ['unit', 'uom', 'unit of measure', 'unit of measurement'],
        'rate' => ['rate', 'unit price', 'price', 'selling price'],
        'amount' => ['amount', 'total', 'value', 'line total'],
        'code' => ['code', 'item code', 'material code', 'sku'],
    ];

    public function extract(string $filePath): array
    {
        $warnings = [];
        try {
            $book = IOFactory::createReaderForFile($filePath)->load($filePath);
        } catch (\Throwable $e) {
            return $this->emptyExtraction(['The workbook could not be read for line-item extraction.']);
        }

        $elements = [];
        $sheetSummaries = [];

        foreach ($book->getAllSheets() as $sheetIndex => $sheet) {
            $maxRow = min($sheet->getHighestDataRow(), 5000);
            $maxCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 40);
            $header = $this->findHeader($sheet, $maxRow, $maxCol);

            if (!$header) {
                $sheetSummaries[] = ['sheet' => $sheet->getTitle(), 'status' => 'no_header', 'lines' => 0];
                continue;
            }

            $lineCount = 0;
            for ($row = $header['row'] + 1; $row <= $maxRow; $row++) {
                $description = $this->text($sheet, $header['columns']['description'] ?? null, $row);
                $quantity = $this->number($sheet, $header['columns']['quantity'] ?? null, $row);
                $unit = $this->text($sheet, $header['columns']['unit'] ?? null, $row);
                $amount = $this->number($sheet, $header['columns']['amount'] ?? null, $row);
                $rate = $this->number($sheet, $header['columns']['rate'] ?? null, $row);

                if ($description === '' || $this->isSummaryRow($description)) {
                    continue;
                }
                // Require at least one commercial line signal. This excludes narrative,
                // address and terms rows without pretending to understand them.
                if ($quantity === null && $unit === '' && $rate === null && $amount === null) {
                    continue;
                }

                $section = $this->text($sheet, $header['columns']['element'] ?? null, $row);
                $sourceKey = "sheet:{$sheetIndex}:row:{$row}";
                $elements[] = [
                    'id' => 'excel-element-' . substr(sha1($sourceKey), 0, 16),
                    'sourceKey' => $sourceKey,
                    'sourceSheet' => $sheet->getTitle(),
                    'sourceRow' => $row,
                    'section' => $section !== '' ? $section : null,
                    'elementType' => $description,
                    'name' => $description,
                    'category' => 'production',
                    'quotedQuantity' => $quantity ?? 0,
                    'quotedUnit' => $unit ?: 'Pcs',
                    'quotedUnitPrice' => $rate,
                    'quotedLineTotal' => $amount,
                    'itemCode' => $this->text($sheet, $header['columns']['code'] ?? null, $row) ?: null,
                    'confidence' => $this->confidence($quantity, $unit, $rate, $amount),
                    'dimensions' => ['length' => '', 'width' => '', 'height' => ''],
                    'isIncluded' => true,
                    'isVisible' => true,
                    // A commercial element is not its own raw material. BOM is
                    // intentionally empty until Materials preparation.
                    'materials' => [],
                ];
                $lineCount++;
            }

            $sheetSummaries[] = ['sheet' => $sheet->getTitle(), 'status' => 'parsed', 'headerRow' => $header['row'], 'lines' => $lineCount];
        }

        if (!$elements) {
            $warnings[] = 'No dependable quote element table was detected. Confirm the workbook uses labelled Description, Quantity and Unit columns.';
        }

        return [
            'schemaVersion' => 1,
            'extractedAt' => now()->toISOString(),
            'reviewStatus' => 'unreviewed',
            'elements' => $elements,
            'summary' => [
                'elementCount' => count($elements),
                'materialCount' => 0,
                'sheets' => $sheetSummaries,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Whitelist a user-reviewed extraction. Source identity and quoted values
     * remain traceable, while client JSON cannot inject inventory links, costs,
     * workflow state, or arbitrary database attributes.
     */
    public function normalizeReview(array $review): array
    {
        $elements = [];
        foreach (array_slice($review['elements'] ?? [], 0, 500) as $elementIndex => $element) {
            if (!is_array($element)) continue;
            $name = trim((string) ($element['name'] ?? ''));
            if ($name === '') continue;
            $sourceKey = trim((string) ($element['sourceKey'] ?? '')) ?: "review:element:{$elementIndex}";
            $quantity = is_numeric($element['quotedQuantity'] ?? null) ? max(0, (float) $element['quotedQuantity']) : 0;
            $unit = trim((string) ($element['quotedUnit'] ?? '')) ?: 'Pcs';
            $elements[] = [
                'id' => (string) ($element['id'] ?? ('review-element-' . substr(sha1($sourceKey), 0, 16))),
                'sourceKey' => $sourceKey,
                'sourceSheet' => isset($element['sourceSheet']) ? mb_substr((string) $element['sourceSheet'], 0, 255) : null,
                'sourceRow' => is_numeric($element['sourceRow'] ?? null) ? (int) $element['sourceRow'] : null,
                'section' => isset($element['section']) ? mb_substr(trim((string) $element['section']), 0, 500) : null,
                'elementType' => mb_substr(trim((string) ($element['elementType'] ?? $name)), 0, 500),
                'name' => mb_substr($name, 0, 500),
                'category' => in_array($element['category'] ?? null, ['production', 'hire', 'outsourced'], true) ? $element['category'] : 'production',
                'quotedQuantity' => round($quantity, 4),
                'quotedUnit' => mb_substr($unit, 0, 100),
                'quotedUnitPrice' => is_numeric($element['quotedUnitPrice'] ?? null) ? round((float) $element['quotedUnitPrice'], 4) : null,
                'quotedLineTotal' => is_numeric($element['quotedLineTotal'] ?? null) ? round((float) $element['quotedLineTotal'], 4) : null,
                'itemCode' => isset($element['itemCode']) ? mb_substr(trim((string) $element['itemCode']), 0, 100) : null,
                'confidence' => is_numeric($element['confidence'] ?? null) ? min(1, max(0, (float) $element['confidence'])) : null,
                'dimensions' => ['length' => '', 'width' => '', 'height' => ''],
                'isIncluded' => (bool) ($element['isIncluded'] ?? true),
                'isVisible' => (bool) ($element['isVisible'] ?? true),
                'materials' => [],
            ];
        }

        return [
            'schemaVersion' => 1,
            'extractedAt' => $review['extractedAt'] ?? now()->toISOString(),
            'reviewedAt' => now()->toISOString(),
            'reviewedBy' => auth()->id(),
            'reviewStatus' => 'reviewed',
            'elements' => $elements,
            'summary' => [
                'elementCount' => count(array_filter($elements, fn ($element) => $element['isVisible'])),
                'materialCount' => 0,
                'sheets' => is_array($review['summary']['sheets'] ?? null) ? $review['summary']['sheets'] : [],
            ],
            'warnings' => array_values(array_filter(array_map('strval', array_slice($review['warnings'] ?? [], 0, 100)))),
        ];
    }

    private function findHeader($sheet, int $maxRow, int $maxCol): ?array
    {
        $best = null;
        foreach (range(1, min($maxRow, 80)) as $row) {
            $columns = [];
            foreach (range(1, $maxCol) as $col) {
                $label = Str::lower(trim((string) $sheet->getCell([$col, $row])->getFormattedValue()));
                $label = preg_replace('/\s+/', ' ', $label);
                foreach (self::HEADERS as $meaning => $aliases) {
                    if (!isset($columns[$meaning]) && in_array($label, $aliases, true)) {
                        $columns[$meaning] = $col;
                    }
                }
            }
            $score = isset($columns['description']) ? 3 : 0;
            $score += isset($columns['quantity']) ? 2 : 0;
            $score += isset($columns['unit']) ? 2 : 0;
            $score += isset($columns['rate']) ? 1 : 0;
            $score += isset($columns['amount']) ? 1 : 0;
            if ($score >= 5 && (!$best || $score > $best['score'])) {
                $best = compact('row', 'columns', 'score');
            }
        }
        return $best;
    }

    private function text($sheet, ?int $column, int $row): string
    {
        if (!$column) return '';
        try { return trim((string) $sheet->getCell([$column, $row])->getFormattedValue()); }
        catch (\Throwable $e) { return ''; }
    }

    private function number($sheet, ?int $column, int $row): ?float
    {
        if (!$column) return null;
        try { $value = $sheet->getCell([$column, $row])->getCalculatedValue(); }
        catch (\Throwable $e) { return null; }
        if (is_numeric($value)) return round((float) $value, 4);
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        return $clean !== '' && is_numeric($clean) ? round((float) $clean, 4) : null;
    }

    private function isSummaryRow(string $description): bool
    {
        return (bool) preg_match('/^(sub\s*total|grand\s*total|total|vat|tax|discount|deposit)\b/i', trim($description));
    }

    private function confidence(?float $quantity, string $unit, ?float $rate, ?float $amount): float
    {
        return round(min(1, .45 + ($quantity !== null ? .2 : 0) + ($unit !== '' ? .2 : 0) + (($rate !== null || $amount !== null) ? .15 : 0)), 2);
    }

    private function emptyExtraction(array $warnings): array
    {
        return ['schemaVersion' => 1, 'extractedAt' => now()->toISOString(), 'reviewStatus' => 'unreviewed', 'elements' => [], 'summary' => ['elementCount' => 0, 'materialCount' => 0, 'sheets' => []], 'warnings' => $warnings];
    }
}
