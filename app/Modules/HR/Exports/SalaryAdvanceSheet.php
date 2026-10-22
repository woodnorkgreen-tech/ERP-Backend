<?php

namespace App\Modules\HR\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * One titled, filterable table on its own sheet: a heading block (what this
 * is, which filters, who ran it), a frozen header row with Excel filters,
 * typed columns (money, percent, date, text) and an optional totals row.
 *
 * Columns: [key, label, type ('text'|'money'|'percent'|'date'|'number'), width].
 */
class SalaryAdvanceSheet extends DefaultValueBinder implements FromArray, WithTitle, WithEvents, WithCustomValueBinder, WithStrictNullComparison
{
    private const MONEY = '#,##0.00';

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string, 3?: int}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $heading  lines above the table
     * @param  array<int, string>  $totals  column keys to sum in a totals row
     * @param  callable|null  $flag  row => colour hex for rows needing attention
     */
    public function __construct(
        private readonly string $title,
        private readonly array $heading,
        private readonly array $columns,
        private readonly array $rows,
        private readonly array $totals = [],
        private $flag = null,
        private readonly ?string $emptyMessage = null,
        private readonly array $shade = [],
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    /** The heading block sits directly above the header (an empty spacer row is dropped by the writer). */
    private function headerRow(): int
    {
        return count($this->heading) + 1;
    }

    public function array(): array
    {
        $out = array_map(fn ($line) => [$line], $this->heading);
        $out[] = array_map(fn ($column) => $column[1], $this->columns);
        foreach ($this->rows as $row) {
            $out[] = array_map(fn ($column) => $this->cell($row[$column[0]] ?? null, $column[2] ?? 'text'), $this->columns);
        }
        if (! $this->rows && $this->emptyMessage) {
            $out[] = [$this->emptyMessage];
        }

        return $out;
    }

    private function cell(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') return null;
        if ($type === 'date') {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable((string) $value));
        }
        if (in_array($type, ['money', 'percent', 'number'], true)) return (float) $value;

        return $value;
    }

    /** Account numbers, IDs and phones stay exact text: no 1.2E+10, no lost leading zero. */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && preg_match('/^\+?\d{6,}$/', $value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $columnCount = count($this->columns);
            $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
            $header = $this->headerRow();
            $firstData = $header + 1;
            $lastData = $header + count($this->rows);
            $widths = array_map(fn ($column) => $column[3] ?? 16, $this->columns);

            $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
            foreach ($widths as $index => $width) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
            }

            // Heading block: each line spans the table and wraps within it.
            $tableWidth = array_sum($widths);
            foreach ($this->heading as $offset => $line) {
                $row = $offset + 1;
                $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
                $style = $sheet->getStyle("A{$row}");
                $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
                $style->getFont()->setSize($row === 1 ? 14 : 10)->setBold($row === 1)->getColor()->setRGB($row === 1 ? '14532D' : '475569');
                if ($row === count($this->heading) && $row > 1) $style->getFont()->setItalic(true);
                $sheet->getRowDimension($row)->setRowHeight($this->fitHeight([(string) $line], [$tableWidth], $row === 1 ? 20 : 14));
            }

            // Header row
            $sheet->getStyle("A{$header}:{$lastColumn}{$header}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']]],
            ]);
            $sheet->getRowDimension($header)->setRowHeight($this->fitHeight(array_map(fn ($c) => $c[1], $this->columns), $widths, 15) + 4);
            $sheet->freezePane('A'.$firstData);

            if (! $this->rows) {
                $sheet->mergeCells("A{$firstData}:{$lastColumn}{$firstData}");
                $sheet->getStyle("A{$firstData}")->getAlignment()->setWrapText(true);
                $sheet->getStyle("A{$firstData}")->getFont()->setItalic(true)->getColor()->setRGB('64748B');

                return;
            }

            // Every data cell wraps; alignment and number format follow the column type.
            $body = "A{$firstData}:{$lastColumn}{$lastData}";
            $sheet->getStyle($body)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle($body)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            foreach ($this->columns as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $range = "{$letter}{$firstData}:{$letter}{$lastData}";
                $type = $column[2] ?? 'text';
                $format = ['money' => self::MONEY, 'percent' => '0.0%', 'date' => 'dd-mmm-yyyy', 'number' => '#,##0'][$type] ?? null;
                if ($format) $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
                $sheet->getStyle($range)->getAlignment()->setHorizontal(match ($type) {
                    'money', 'percent', 'number' => Alignment::HORIZONTAL_RIGHT,
                    'date' => Alignment::HORIZONTAL_CENTER,
                    default => Alignment::HORIZONTAL_LEFT,
                });
            }

            // Banding, then rows that need attention, then columns filled in after export.
            foreach (array_values($this->rows) as $offset => $row) {
                $line = $firstData + $offset;
                $colour = $this->flag ? ($this->flag)($row) : null;
                $colour ??= $offset % 2 ? 'F8FAFC' : null;
                if ($colour) {
                    $sheet->getStyle("A{$line}:{$lastColumn}{$line}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colour);
                }
                $texts = array_map(fn ($column) => $this->display($row[$column[0]] ?? null, $column[2] ?? 'text'), $this->columns);
                $sheet->getRowDimension($line)->setRowHeight($this->fitHeight($texts, $widths, 15));
            }
            foreach ($this->columns as $index => $column) {
                if (! in_array($column[0], $this->shade, true)) continue;
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getStyle("{$letter}{$firstData}:{$letter}{$lastData}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7D6');
            }

            $sheet->setAutoFilter("A{$header}:{$lastColumn}{$lastData}");

            // Totals
            if ($this->totals) {
                $totalRow = $lastData + 1;
                $sheet->setCellValue("A{$totalRow}", 'TOTAL ('.count($this->rows).')');
                foreach ($this->columns as $index => $column) {
                    if (! in_array($column[0], $this->totals, true)) continue;
                    $letter = Coordinate::stringFromColumnIndex($index + 1);
                    $sheet->setCellValue("{$letter}{$totalRow}", "=SUBTOTAL(9,{$letter}{$firstData}:{$letter}{$lastData})");
                    $sheet->getStyle("{$letter}{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY);
                    $sheet->getStyle("{$letter}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                    'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM], 'bottom' => ['borderStyle' => Border::BORDER_MEDIUM]],
                ]);
                $sheet->getRowDimension($totalRow)->setRowHeight(18);
            }

            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header);
            $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
            $sheet->getHeaderFooter()->setOddFooter('&L&8'.$this->title.'&R&8Page &P of &N');
        }];
    }

    /** The text a cell shows, for sizing its row. */
    private function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '') return '';
        return match ($type) {
            'money' => number_format((float) $value, 2),
            'percent' => number_format((float) $value * 100, 1).'%',
            'date' => '00-Mmm-0000',
            default => (string) $value,
        };
    }

    /**
     * A row height that shows every wrapped line. Excel does not always re-fit
     * rows written by a library, so the height is set from the text itself:
     * about one character per unit of column width at 10pt.
     *
     * @param  array<int, string>  $texts
     * @param  array<int, float|int>  $widths
     */
    private function fitHeight(array $texts, array $widths, float $lineHeight): float
    {
        $lines = 1;
        foreach ($texts as $index => $text) {
            $perLine = max(1, (int) floor(($widths[$index] ?? 16) * 1.1) - 1);
            $count = 0;
            foreach (preg_split('/\R/', (string) $text) as $segment) {
                $count += max(1, (int) ceil(mb_strlen($segment) / $perLine));
            }
            $lines = max($lines, $count);
        }

        return $lines * $lineHeight + 3;
    }
}
