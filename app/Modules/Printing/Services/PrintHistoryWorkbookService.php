<?php

namespace App\Modules\Printing\Services;

use App\Modules\Assets\Models\Asset;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class PrintHistoryWorkbookService
{
    private const SHEETS = [
        'Matte Sticker' => ['header' => 3, 'date' => 'A', 'material' => 'B', 'project_ref' => 'C', 'project' => 'D', 'item' => 'E', 'type' => 'F', 'status' => 'G', 'art_width' => 'K', 'art_height' => 'L', 'quantity' => 'M', 'print_width' => 'P', 'print_length' => 'O', 'usage' => 'R', 'family' => 'Matte Sticker', 'machine' => 'Wit-Color'],
        'Satin Media' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'S', 'family' => 'Satin Media', 'machine' => 'X-Rowland'],
        'PVC White Back Banner' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'R', 'family' => 'PVC White Back Banner', 'machine' => 'X-Rowland'],
        ' Gloss Sticker' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'R', 'family' => 'Gloss Sticker', 'machine' => 'Wit-Color'],
        'PVC Black back ' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'R', 'family' => 'PVC Black Back Banner', 'machine' => 'X-Rowland'],
        'FLAG X-R' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'client' => 'E', 'project' => 'F', 'item' => 'G', 'type' => 'H', 'status' => 'I', 'art_width' => 'M', 'art_height' => 'N', 'quantity' => 'O', 'print_width' => 'P', 'print_length' => 'Q', 'usage' => 'S', 'family' => 'Flag Fabric', 'machine' => 'X-Rowland'],
        'Plotting' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'S', 'family' => 'Plotting', 'machine' => 'Plotter'],
        'Sublimation Fabric' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'S', 'family' => 'Sublimation Fabric', 'machine' => 'Sublimation Printer'],
        'BACKLIT BANNER' => ['header' => 2, 'date' => 'B', 'material' => 'C', 'project_ref' => 'D', 'project' => 'E', 'item' => 'F', 'type' => 'G', 'status' => 'H', 'art_width' => 'L', 'art_height' => 'M', 'quantity' => 'N', 'print_width' => 'O', 'print_length' => 'P', 'usage' => 'S', 'family' => 'Backlit Banner', 'machine' => 'X-Rowland'],
    ];

    public function parse(string $path, string $cutoffDate): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(array_keys(self::SHEETS));
        $workbook = $reader->load($path);
        $rows = [];
        $summary = ['ready' => 0, 'already_imported' => 0, 'excluded' => [], 'date_inferred' => 0, 'project_inferred' => 0, 'usage_review' => 0, 'missing_project' => 0, 'materials' => [], 'sheets' => [], 'samples' => ['ready' => [], 'excluded' => [], 'review' => []]];
        $recognized = 0;

        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $sheetName = $sheet->getTitle();
            $map = self::SHEETS[$sheetName] ?? null;
            if (!$map) {
                continue;
            }
            $recognized++;
            $this->assertLayout($sheet, $map);
            $sheetSummary = ['ready' => 0, 'excluded' => 0, 'already_imported' => 0];
            $candidateRows = [];
            $previousDate = null;
            for ($number = $map['header'] + 1; $number <= $sheet->getHighestDataRow(); $number++) {
                $rawDate = $this->raw($sheet, $map['date'], $number);
                $date = $this->parseDate($rawDate);
                if ($date !== null && $date !== 'invalid') {
                    $previousDate = $date;
                }
                $data = $this->readRow($sheet, $number, $map);
                if (!$this->isCandidate($data)) {
                    continue;
                }
                $data['sheet'] = $sheetName;
                $data['row'] = $number;
                $data['source_key'] = hash('sha256', 'printing-material-tracker-v1|' . $sheetName . '|' . $number);
                $data['work_date'] = $date && $date !== 'invalid' ? $date : ($date === 'invalid' ? null : $previousDate);
                $data['date_source'] = $date && $date !== 'invalid' ? 'recorded' : ($date === 'invalid' ? 'invalid' : 'previous');
                $data['project_inferred'] = false;
                $data['exclusion'] = $this->exclusion($data, $cutoffDate);
                $candidateRows[] = $data;
            }

            $this->inferProjects($candidateRows);
            foreach ($candidateRows as $data) {
                $key = $data['source_key'];
                $rows[$key] = $data;
            }
            $summary['sheets'][$sheetName] = $sheetSummary;
        }

        if ($recognized !== count(self::SHEETS)) {
            throw new RuntimeException('This workbook does not have the expected printing tracker tabs.');
        }
        $workbook->disconnectWorksheets();

        $existing = [];
        foreach (array_chunk(array_keys($rows), 400) as $keys) {
            foreach (DB::table('print_history_source_rows')->whereIn('source_key', $keys)->pluck('source_key') as $key) {
                $existing[$key] = true;
            }
        }
        foreach ($rows as $key => &$data) {
            $sheet = &$summary['sheets'][$data['sheet']];
            if (isset($existing[$key])) {
                $data['exclusion'] = 'already_imported';
                $summary['already_imported']++;
                $sheet['already_imported']++;
            } elseif ($data['exclusion']) {
                $reason = $data['exclusion'];
                $summary['excluded'][$reason] = ($summary['excluded'][$reason] ?? 0) + 1;
                $sheet['excluded']++;
            } else {
                $summary['ready']++;
                $sheet['ready']++;
                if ($data['date_source'] === 'previous') $summary['date_inferred']++;
                if ($data['project_inferred']) $summary['project_inferred']++;
                if (!$data['project_name']) $summary['missing_project']++;
                $family = $data['material_family'];
                $summary['materials'][$family] ??= ['jobs' => 0, 'running_m' => 0, 'area_sqm' => 0];
                $summary['materials'][$family]['jobs']++;
                $summary['materials'][$family]['running_m'] += $data['running_m'] ?? 0;
                $summary['materials'][$family]['area_sqm'] += $data['area_sqm'] ?? 0;
            }
            $review = !$data['exclusion'] ? $this->reviewReasons($data) : [];
            if ($review) $summary['usage_review']++;
            $sample = [
                'sheet' => $data['sheet'], 'row' => $data['row'], 'date' => $data['work_date'],
                'date_source' => $data['date_source'], 'project' => $data['project_name'],
                'item' => $data['item'], 'material' => $data['material_family'],
                'machine' => $data['machine_name'], 'running_m' => $data['running_m'],
                'area_sqm' => $data['area_sqm'], 'reason' => $data['exclusion'], 'review' => $review,
            ];
            $group = $data['exclusion'] ? 'excluded' : 'ready';
            if (count($summary['samples'][$group]) < 25) $summary['samples'][$group][] = $sample;
            if ($review && count($summary['samples']['review']) < 25) $summary['samples']['review'][] = $sample;
            unset($sheet);
        }
        unset($data);
        foreach ($summary['materials'] as &$material) {
            $material['running_m'] = round($material['running_m'], 3);
            $material['area_sqm'] = round($material['area_sqm'], 3);
        }
        unset($material);

        return ['rows' => $rows, 'summary' => $summary];
    }

    private function reviewReasons(array $data): array
    {
        $reasons = [];
        if (!$data['project_name']) $reasons[] = 'missing_project';
        if ($data['usage_unit'] === 'm2') $reasons[] = 'area_in_usage_column';
        elseif ($data['running_m'] === null) $reasons[] = 'missing_running_metres';
        if ($data['area_sqm'] === null) $reasons[] = 'missing_or_unclear_dimensions';
        if ($data['running_m'] && $data['area_sqm'] && $data['running_m'] > $data['area_sqm'] * 5) {
            $reasons[] = 'running_metres_high_vs_area';
        }
        return $reasons;
    }

    private function assertLayout(Worksheet $sheet, array $map): void
    {
        $header = $map['header'];
        $date = strtolower((string) $this->raw($sheet, $map['date'], $header));
        $item = strtolower((string) $this->raw($sheet, $map['item'], $header));
        if ($date !== 'date' || !str_contains($item, 'description')) {
            throw new RuntimeException("The columns in {$sheet->getTitle()} do not match the printing tracker layout.");
        }
    }

    private function readRow(Worksheet $sheet, int $number, array $map): array
    {
        $values = [];
        foreach (['date', 'material', 'project_ref', 'project', 'item', 'type', 'status', 'art_width', 'art_height', 'quantity', 'print_width', 'print_length', 'usage'] as $field) {
            $values[$field] = $this->value($sheet, $map[$field], $number);
        }
        $values['client'] = isset($map['client']) ? $this->value($sheet, $map['client'], $number) : null;
        $values['usage_formula'] = $this->raw($sheet, $map['usage'], $number);
        $values['project_name'] = $this->clean($values['project']);
        $values['project_reference'] = $this->clean($values['project_ref']);
        $values['material_label'] = $this->clean($values['material']);
        $values['item'] = $this->clean($values['item']);
        $values['material_family'] = $this->family($map['family'], $values['material_label']);
        $values['machine_name'] = $map['machine'];
        $width = $this->positive($values['art_width']);
        $height = $this->positive($values['art_height']);
        $quantity = $this->positive($values['quantity']) ?? 1;
        $values['area_sqm'] = $width && $height && $width <= 20 && $height <= 20
            ? round($width * $height * $quantity, 3) : null;
        $usage = $this->positive($values['usage']);
        $areaRaw = $width && $height ? $width * $height * $quantity : null;
        $isAreaInRunningColumn = $map['family'] === 'Sublimation Fabric' && $usage && $areaRaw
            && abs($usage - $areaRaw) <= max(0.01, $areaRaw * 0.001)
            && (!$this->positive($values['print_length']) || abs($usage - (float)$values['print_length']) > 0.01);
        $values['usage_unit'] = !$usage ? null : ($isAreaInRunningColumn ? 'm2' : 'm');
        $values['recorded_usage_value'] = $usage;
        $values['running_m'] = $values['usage_unit'] === 'm' ? $usage : null;
        return $values;
    }

    private function isCandidate(array $data): bool
    {
        $type = strtolower((string)$this->clean($data['type']));
        if (!in_array($type, ['original order', 'reprint'], true)) return false;
        $context = implode(' ', array_map(fn ($value) => (string) $value, [
            $data['material_label'], $data['project_name'], $data['item'], $data['project_reference'],
        ]));
        if (preg_match('/restock|re-stock|new roll|old stock|opening stock/i', $context)) return false;
        return $data['item'] !== null || $data['project_name'] !== null || $data['project_reference'] !== null;
    }

    private function exclusion(array $data, string $cutoffDate): ?string
    {
        if ($data['date_source'] === 'invalid') return 'invalid_date';
        if (!$data['work_date']) return 'missing_date';
        if ($data['work_date'] > $cutoffDate) return 'future_date';
        if ($data['work_date'] < '2025-01-01') return 'date_outlier';
        if (strtolower((string)$this->clean($data['status'])) !== 'completed') return 'not_completed';
        return null;
    }

    private function inferProjects(array &$rows): void
    {
        $count = count($rows);
        $previous = array_fill(0, $count, null);
        $next = array_fill(0, $count, null);
        $last = null;
        for ($i = 0; $i < $count; $i++) {
            $previous[$i] = $last;
            if ($rows[$i]['project_name']) $last = $i;
        }
        $last = null;
        for ($i = $count - 1; $i >= 0; $i--) {
            $next[$i] = $last;
            if ($rows[$i]['project_name']) $last = $i;
        }
        for ($i = 0; $i < $count; $i++) {
            if ($rows[$i]['project_name'] || $previous[$i] === null || $next[$i] === null) continue;
            $above = $rows[$previous[$i]];
            $below = $rows[$next[$i]];
            if ($this->normalize($above['project_name']) !== $this->normalize($below['project_name'])) continue;
            $aRef = $this->normalize($above['project_reference']);
            $bRef = $this->normalize($below['project_reference']);
            $ownRef = $this->normalize($rows[$i]['project_reference']);
            if ($aRef && $bRef && $aRef !== $bRef) continue;
            if ($ownRef && $aRef && $ownRef !== $aRef) continue;
            if ($ownRef && $bRef && $ownRef !== $bRef) continue;
            $rows[$i]['project_name'] = $above['project_name'];
            $rows[$i]['project_reference'] ??= $above['project_reference'] ?: $below['project_reference'];
            $rows[$i]['project_inferred'] = true;
        }
    }

    private function family(string $sheetFamily, ?string $rawMaterial): string
    {
        if ($sheetFamily === 'Plotting') {
            return str_contains(strtoupper((string)$rawMaterial), 'PVC') ? 'PVC Plotting' : 'Matte Sticker';
        }
        if ($sheetFamily === 'Gloss Sticker' && preg_match('/matt/i', (string)$rawMaterial)) return 'Matte Sticker';
        return $sheetFamily;
    }

    private function value(Worksheet $sheet, string $column, int $row): mixed
    {
        $cell = $sheet->getCell($column . $row);
        $value = $cell->getValue();
        if (is_string($value) && str_starts_with($value, '=')) $value = $cell->getOldCalculatedValue();
        return is_string($value) ? trim($value) : $value;
    }

    private function raw(Worksheet $sheet, string $column, int $row): mixed
    {
        $value = $sheet->getCell($column . $row)->getValue();
        return is_string($value) ? trim($value) : $value;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim(preg_replace('/\s+/u', ' ', (string)$value));
        return in_array($text, ['', '"', '-', '—'], true) ? null : $text;
    }

    private function positive(mixed $value): ?float
    {
        return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$value)));
    }

    private function parseDate(mixed $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) return null;
        if (is_numeric($value) && (float)$value >= 40000 && (float)$value < 60000) {
            return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d');
        }
        $format = preg_match('/^\d{4}-/', $value) ? 'Y-m-d'
            : (preg_match('/\d{4}$/', $value) ? (str_contains($value, '.') ? 'd.m.Y' : 'd/m/Y')
            : (str_contains($value, '.') ? 'd.m.y' : 'd/m/y'));
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
            ? $date->format('Y-m-d') : 'invalid';
    }

    public function machineIds(): array
    {
        $names = array_unique(array_column(self::SHEETS, 'machine'));
        $assets = Asset::query()->whereIn('name', $names)->where('is_active', true)->get()->keyBy('name');
        foreach ($names as $name) {
            if (!isset($assets[$name])) throw new RuntimeException("Machine {$name} is missing from Assets.");
        }
        return $assets->map(fn (Asset $asset) => $asset->id)->all();
    }

    public function materialIds(): array
    {
        return [
            'Matte Sticker' => LibraryMaterial::query()->where('material_name', 'Matt white sticker')->value('id'),
            'Gloss Sticker' => LibraryMaterial::query()->where('material_name', 'Glosy Sticker white')->value('id'),
        ];
    }
}
