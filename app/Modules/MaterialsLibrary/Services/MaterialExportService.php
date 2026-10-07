<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Support\MaterialControl;
use App\Modules\MaterialsLibrary\Support\MaterialWorkbook;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MaterialExportService
{
    public function downloadTemplate($workstationId = null)
    {
        if ($workstationId) {
            Workstation::findOrFail($workstationId);
        }

        return $this->download(false, $workstationId);
    }

    public function downloadAll()
    {
        return $this->download(true);
    }

    private function download(bool $includeMaterials, ?int $workstationId = null)
    {
        $book = $this->workbook($includeMaterials, $workstationId);
        $filename = $includeMaterials ? 'material_library_'.now()->format('Ymd').'.xlsx' : 'material_library_template.xlsx';

        return response()->streamDownload(function () use ($book) {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function workbook(bool $includeMaterials = true, ?int $workstationId = null): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(MaterialWorkbook::SHEET);
        $categories = MaterialCategory::with(['parent.itemType', 'itemType'])->get();
        $fields = [];
        foreach ($categories as $category) {
            foreach ($category->resolvedAttributeSchema() as $field) {
                $fields[$field['key']] = $field;
            }
        }
        $materials = $includeMaterials ? LibraryMaterial::with(['workstation', 'materialCategory.parent', 'itemType', 'baseUom', 'purchaseUom', 'issueUom', 'uomConversions', 'stock'])->orderBy('id')->get() : collect();
        // Legacy/custom specifications deserve editable columns too, even when
        // the category blueprint has not declared them yet. Complex values stay in JSON.
        $attributeMaterials = $includeMaterials ? $materials : LibraryMaterial::select(['id', 'attributes'])
            ->when($workstationId, fn ($query) => $query->where('workstation_id', $workstationId))->cursor();
        foreach ($attributeMaterials as $material) {
            foreach ($material->attributes['attributes'] ?? $material->attributes ?? [] as $key => $value) {
                if (is_string($key) && $key !== '' && is_scalar($value)) {
                    $fields[$key] ??= ['key' => $key, 'label' => Str::headline($key), 'type' => 'text'];
                }
            }
        }
        $headers = array_keys(MaterialWorkbook::COLUMNS);
        foreach ($fields as $key => $field) {
            $headers[] = 'Attribute: '.$key;
        }
        $sheet->fromArray($headers, null, 'A1');
        $row = 2;
        foreach ($materials as $material) {
            foreach (MaterialWorkbook::COLUMNS as $header => $key) {
                $value = match ($key) {
                    'id' => $material->id,
                    'workstation_id' => MaterialWorkbook::choice($material->workstation),
                    'material_category_id' => MaterialWorkbook::choice($material->materialCategory),
                    'item_type_id' => MaterialWorkbook::choice($material->itemType),
                    'base_uom_id' => MaterialWorkbook::choice($material->baseUom),
                    'purchase_uom_id' => MaterialWorkbook::choice($material->purchaseUom),
                    'issue_uom_id' => MaterialWorkbook::choice($material->issueUom),
                    'purchase_factor' => $material->uomConversions->firstWhere('from_uom_id', $material->purchase_uom_id)?->factor,
                    'issue_factor' => $material->uomConversions->firstWhere('from_uom_id', $material->issue_uom_id)?->factor,
                    'attributes_json' => json_encode($material->attributes ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'effective_date' => $material->effective_date?->format('Y-m-d'),
                    'legacy_category' => $material->category, 'legacy_subcategory' => $material->subcategory,
                    'legacy_uom' => $material->unit_of_measure, 'current_unit_cost' => $material->unit_cost,
                    'stock_on_hand' => $material->stock?->quantity_on_hand, 'inventory_visible' => ($material->is_inventory_visible ?? true) ? 'Yes' : 'No',
                    default => str_starts_with($key, 'is_') ? ($material->$key ? 'Yes' : 'No') : $material->$key,
                };
                $this->cell($sheet, array_search($header, $headers, true) + 1, $row, $value);
            }
            $attributes = $material->attributes['attributes'] ?? $material->attributes ?? [];
            foreach ($fields as $key => $field) {
                $this->cell($sheet, array_search('Attribute: '.$key, $headers, true) + 1, $row, $attributes[$key] ?? null);
            }
            $row++;
        }
        if (! $includeMaterials && $workstationId) {
            $this->cell($sheet, 4, 2, MaterialWorkbook::choice(Workstation::findOrFail($workstationId)));
        }
        $lists = $book->createSheet()->setTitle('Lists');
        $listColumn = 1;
        $lastRow = min(1048576, max($row + 200, 1001));
        $choices = [
            'Workshop area' => Workstation::active()->get()->map(fn ($v) => MaterialWorkbook::choice($v))->all(),
            'Category' => $categories->filter(fn ($c) => $c->is_active && $c->is_selectable && ! $categories->contains(fn ($child) => $child->parent_id === $c->id && $child->is_active))->map(fn ($v) => MaterialWorkbook::choice($v))->values()->all(),
            'Item type' => MaterialItemType::where('is_active', true)->get()->map(fn ($v) => MaterialWorkbook::choice($v))->all(),
            'Status' => MaterialControl::STATUSES, 'Issue disposition' => MaterialControl::DISPOSITIONS, 'Tracking mode' => MaterialControl::TRACKING_MODES,
        ];
        $units = UnitOfMeasure::where('is_active', true)->get();
        $uoms = $units->map(fn ($v) => MaterialWorkbook::choice($v))->all();
        foreach (['Stock unit', 'Purchase unit', 'Issue unit'] as $label) {
            $choices[$label] = $uoms;
        }
        foreach (['Hazardous', 'Serialized', 'Batch controlled', 'Expiry controlled', 'Project chargeable'] as $label) {
            $choices[$label] = ['Yes', 'No'];
        }
        $choiceNames = [];
        foreach ($choices as $label => $values) {
            $name = $this->list($book, $lists, $listColumn++, $label, $values);
            $choiceNames[$label] = $name;
            $this->dropdown($sheet, Coordinate::stringFromColumnIndex(array_search($label, $headers, true) + 1).'2', $name, $lastRow);
        }
        // Core dependencies follow category selection just like specification choices.
        foreach (['Item type', 'Stock unit'] as $label) {
            $lookup = $listColumn;
            $listColumn += 2;
            $lookupRow = 2;
            foreach ($categories as $category) {
                $owner = $category->itemType ?? $category->parent?->itemType;
                $allowed = $category->allowedStockUomCodes();
                $values = $label === 'Item type'
                    ? ($owner ? ($owner->is_active ? [MaterialWorkbook::choice($owner)] : []) : $choices[$label])
                    : ($allowed ? $units->filter(fn ($unit) => in_array($unit->code, $allowed, true))->map(fn ($unit) => MaterialWorkbook::choice($unit))->values()->all() : $uoms);
                $name = $this->list($book, $lists, $listColumn++, 'category_'.$category->id.'_'.$label, $values);
                $lists->setCellValue([$lookup, $lookupRow], MaterialWorkbook::choice($category));
                $lists->setCellValue([$lookup + 1, $lookupRow], $name);
                $lookupRow++;
            }
            $range = "'Lists'!\$".Coordinate::stringFromColumnIndex($lookup).'$2:$'.Coordinate::stringFromColumnIndex($lookup + 1).'$'.max(2, $lookupRow - 1);
            $column = Coordinate::stringFromColumnIndex(array_search($label, $headers, true) + 1);
            $this->dropdown($sheet, $column.'2', 'INDIRECT(IFERROR(VLOOKUP($E2,'.$range.',2,FALSE),"'.$choiceNames[$label].'"))', $lastRow, true);
        }
        // Specifications use the selected category's own inherited choices.
        foreach ($fields as $key => $field) {
            if (! $categories->contains(fn ($cat) => collect($cat->resolvedAttributeSchema())->contains(fn ($f) => $f['key'] === $key && $f['type'] === 'select'))) {
                continue;
            }
            $lookup = $listColumn;
            $listColumn += 2;
            $lookupLetter = Coordinate::stringFromColumnIndex($lookup);
            $lookupRow = 2;
            foreach ($categories as $cat) {
                $schema = collect($cat->resolvedAttributeSchema())->firstWhere('key', $key);
                $name = $this->list($book, $lists, $listColumn++, 'category_'.$cat->id.'_'.$key, ($schema['type'] ?? null) === 'select' ? ($schema['options'] ?? []) : []);
                $lists->setCellValue([$lookup, $lookupRow], MaterialWorkbook::choice($cat));
                $lists->setCellValue([$lookup + 1, $lookupRow], $name);
                $lookupRow++;
            }
            // Reserve adjacent lookup column before subsequent list fields can reuse it.
            $range = "'Lists'!\${$lookupLetter}\$2:\$".Coordinate::stringFromColumnIndex($lookup + 1).'$'.max(2, $lookupRow - 1);
            $column = Coordinate::stringFromColumnIndex(array_search('Attribute: '.$key, $headers, true) + 1);
            $this->dropdown($sheet, $column.'2', 'INDIRECT(IFERROR(VLOOKUP($E2,'.$range.',2,FALSE),"empty_choices"))', $lastRow, true);
        }
        $this->list($book, $lists, $listColumn++, 'empty_choices', [], 'empty_choices');
        $guide = $book->createSheet()->setTitle('Instructions');
        $guide->fromArray([
            ['Material library workbook'],
            ['Edit the Materials sheet and upload this same .xlsx file. All non-deleted materials are included, across workshop areas, statuses and inventory visibility.'],
            ['Keep Material ID to update an existing material. For a new item leave Material ID blank; supply an unused Item code or leave it blank for automatic generation.'],
            ['Use dropdowns for category, workshop area, item type, status, issue disposition, tracking mode, units and Yes/No flags. Choices come from the current registry.'],
            ['Select Category first. Item type and Stock unit dropdowns show that category’s allowed choices. When changing Category, reselect Stock unit if required. Category owns Item type: the importer always uses its owner, including when retrying the same workbook.'],
            ['Purchase / issue conversion factor means how many stock units are in one purchase / issue unit; required when the units differ.'],
            ['Additional attributes (JSON) preserves custom specifications. Attribute columns override the same keys in this JSON, including clearing a value.'],
            ['Effective date: YYYY-MM-DD. Blank editable cells clear optional values; omitted columns on an update keep existing values.'],
            ['Columns labelled (reference) are informational and are ignored on upload. Stock quantities, valuation and inventory visibility are changed through their existing workflows.'],
            ['Deleted materials are excluded. Rows are validated independently; errors identify the actual worksheet row and do not change that row.'],
            ['Unfinished materials remain Under Review under the same completeness controls as the modal. Stock-unit and board-history restrictions still apply.'],
            ['New rows may be entered below existing ones; dropdowns extend at least 200 rows beyond the export (minimum 1,000 entry rows).'],
        ]);
        $guide->getColumnDimension('A')->setWidth(120);
        $guide->getStyle('A1:A12')->getAlignment()->setWrapText(true);
        for ($i = 2; $i <= 12; $i++) {
            $guide->getRowDimension($i)->setRowHeight(45);
        }
        $end = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('D2');
        $sheet->setAutoFilter('A1:'.$end.max(1, $row - 1));
        $sheet->getStyle('A1:'.$end.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$end.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1E3A5F');
        $sheet->getRowDimension(1)->setRowHeight(42);
        $sheet->getStyle('A1:'.$end.'1')->getAlignment()->setWrapText(true);
        foreach ($headers as $i => $header) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(str_contains($header, 'JSON') ? 55 : 26);
        }
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function cell(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        // User content is never an Excel formula; preserve codes and part-number leading zeros.
        $sheet->setCellValueExplicit([$column, $row], is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : json_encode($value)), DataType::TYPE_STRING);
    }

    private function list(Spreadsheet $book, Worksheet $sheet, int $column, string $label, array $values, ?string $name = null): string
    {
        $letter = Coordinate::stringFromColumnIndex($column);
        $name ??= 'choices_'.$column;
        $this->cell($sheet, $column, 1, $label);
        foreach ($values as $i => $value) {
            $this->cell($sheet, $column, $i + 2, $value);
        }
        $book->addNamedRange(new NamedRange($name, $sheet, '$'.$letter.'$2:$'.$letter.'$'.max(2, count($values) + 1)));

        return $name;
    }

    private function dropdown(Worksheet $sheet, string $cell, string $formula, int $lastRow, bool $expression = false): void
    {
        $validation = $sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorTitle('Choose a listed value')
            ->setError('Select an option from this field’s dropdown.')->setFormula1($formula);
        $column = preg_replace('/\d+/', '', $cell);
        $validation->setSqref($cell.':'.$column.$lastRow);
    }
}
