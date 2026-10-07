<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Controllers\MaterialController;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\MaterialItemType;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use App\Modules\MaterialsLibrary\Models\Workstation;
use App\Modules\MaterialsLibrary\Requests\StoreMaterialRequest;
use App\Modules\MaterialsLibrary\Requests\UpdateMaterialRequest;
use App\Modules\MaterialsLibrary\Support\MaterialWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class MaterialWorkbookImportService
{
    public function import(Worksheet $sheet): array
    {
        $headers = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() !== null) {
                    $label = trim((string) $cell->getValue());
                    if ($label === '') {
                        continue;
                    }
                    if (! array_key_exists($label, MaterialWorkbook::COLUMNS) && ! str_starts_with($label, 'Attribute: ')) {
                        throw ValidationException::withMessages(['file' => 'Unknown workbook column: '.$label.'. Keep the downloaded column headings.']);
                    }
                    if (in_array($label, $headers, true)) {
                        throw ValidationException::withMessages(['file' => 'Duplicate workbook column: '.$label]);
                    }
                    $headers[$cell->getColumn()] = $label;
                }
            }
        }
        if (! in_array('Material name', $headers, true)) {
            throw ValidationException::withMessages(['file' => 'The Materials sheet requires a Material name column.']);
        }
        $results = ['total' => 0, 'success' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];
        $seen = [];
        for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
            $row = [];
            foreach ($headers as $col => $label) {
                $row[$label] = $sheet->getCell($col.$number)->getValue();
            }
            if (! collect($row)->contains(fn ($value) => $value !== null && $value !== '')) {
                continue;
            }
            $results['total']++;
            try {
                foreach ($headers as $col => $label) {
                    if ($sheet->getCell($col.$number)->getDataType() === DataType::TYPE_FORMULA) {
                        throw ValidationException::withMessages(['file' => 'Formulas are not accepted; enter values instead ('.$label.').']);
                    }
                }
                $identity = filled($row['Material ID'] ?? null) ? 'id:'.$row['Material ID'] : (filled($row['Item code'] ?? null) ? 'code:'.$row['Item code'] : null);
                if ($identity && isset($seen[$identity])) {
                    throw ValidationException::withMessages(['file' => 'This material appears more than once in the workbook.']);
                }
                $created = DB::transaction(fn () => $this->save($row));
                if ($identity) {
                    $seen[$identity] = true;
                }
                $results[$created ? 'created' : 'updated']++;
                $results['success']++;
            } catch (\Throwable $e) {
                $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
                $results['errors'][] = 'Row '.$number.': '.$message;
            }
        }

        return $results;
    }

    private function save(array $row): bool
    {
        $material = null;
        if (filled($row['Material ID'] ?? null)) {
            if (! ctype_digit((string) $row['Material ID'])) {
                throw ValidationException::withMessages(['id' => 'Material ID must be an existing numeric ID.']);
            }
            $material = LibraryMaterial::lockForUpdate()->find($row['Material ID']);
            if (! $material) {
                throw ValidationException::withMessages(['id' => 'Material ID does not exist or has been deleted. Leave it blank only for a new material.']);
            }
        } elseif (filled($row['Item code'] ?? null)) {
            $material = LibraryMaterial::where('material_code', $row['Item code'])->lockForUpdate()->first();
        }
        $data = [];
        $references = ['workstation_id' => Workstation::class, 'material_category_id' => MaterialCategory::class,
            'item_type_id' => MaterialItemType::class, 'base_uom_id' => UnitOfMeasure::class, 'purchase_uom_id' => UnitOfMeasure::class, 'issue_uom_id' => UnitOfMeasure::class];
        foreach (MaterialWorkbook::COLUMNS as $label => $field) {
            if (! array_key_exists($label, $row) || $field === 'id' || str_contains($label, '(reference)') || in_array($field, ['attributes_json', 'purchase_factor', 'issue_factor'], true)) {
                continue;
            }
            $value = $row[$label];
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '') {
                $value = null;
            }
            if (isset($references[$field])) {
                if ($value !== null) {
                    if (! preg_match('/^(\d+)(?:\s*\|.*)?$/', (string) $value, $match)) {
                        throw ValidationException::withMessages([$field => 'Choose a listed '.$label.' value.']);
                    }
                    $reference = $references[$field]::find($match[1]);
                    if (! $reference) {
                        throw ValidationException::withMessages([$field => $label.' does not exist.']);
                    }
                    $value = $reference->id;
                    if (! $reference->is_active && (int) $material?->$field !== $value) {
                        throw ValidationException::withMessages([$field => $label.' is inactive.']);
                    }
                }
            } elseif (str_starts_with($field, 'is_')) {
                if ($value === null && ! $material) {
                    continue;
                }
                $value = match (strtolower((string) $value)) {
                    'yes','true','1' => true,'no','false','0' => false,
                    default => throw ValidationException::withMessages([$field => $label.' must be Yes or No.'])
                };
            }
            // These govern identity/control, not optional descriptive fields. An unfinished blank remains unfinished.
            if ($value === null && in_array($field, ['material_code', 'item_status', 'revision_version', 'item_type_id', 'base_uom_id', 'issue_uom_id', 'issue_disposition', 'tracking_mode'], true)) {
                continue;
            }
            $data[$field] = $value;
        }
        if (is_numeric($data['effective_date'] ?? null)) {
            $data['effective_date'] = Date::excelToDateTimeObject($data['effective_date'])->format('Y-m-d');
        }
        $attributes = $material?->attributes ?? [];
        if (array_key_exists('Additional attributes (JSON)', $row)) {
            $raw = $row['Additional attributes (JSON)'];
            $attributes = blank($raw) ? [] : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($attributes)) {
                throw ValidationException::withMessages(['attributes' => 'Additional attributes must be a JSON object.']);
            }
        }
        $values = $attributes['attributes'] ?? $attributes;
        if (! is_array($values)) {
            throw ValidationException::withMessages(['attributes' => 'Attribute values must be a JSON object.']);
        }
        foreach ($row as $label => $value) {
            if (str_starts_with($label, 'Attribute: ')) {
                $key = substr($label, 11);
                if ($value === null || $value === '') {
                    unset($values[$key]);
                } else {
                    // Retain numeric/boolean JSON types on an unchanged round trip.
                    $previous = $values[$key] ?? null;
                    $previousText = is_bool($previous) ? ($previous ? 'true' : 'false') : (is_scalar($previous) ? (string) $previous : null);
                    $values[$key] = $previousText !== null && $previousText === (string) $value ? $previous : $value;
                }
            }
        }
        $attributes['attributes'] = $values;
        $data['attributes'] = $attributes;
        if ($material) {
            // Supply unchanged controls to the modal's cross-field validator for partial column uploads.
            foreach (['material_category_id', 'item_type_id', 'base_uom_id', 'issue_uom_id', 'issue_disposition', 'tracking_mode', 'is_serialized', 'is_batch_controlled', 'is_expiry_controlled', 'minimum_reusable_length_mm', 'minimum_reusable_width_mm', 'minimum_reusable_area_m2'] as $key) {
                if (! array_key_exists($key, $data) && $material->$key !== null) {
                    $data[$key] = $material->$key;
                }
            }
        }
        // Category owns Item type. A workbook can retain its original type after
        // an earlier partial upload already repaired the database. Always derive
        // the owner so importing that same file again remains consistent.
        if (isset($data['material_category_id'])) {
            $category = MaterialCategory::with('parent')->find($data['material_category_id']);
            $owner = $category?->item_type_id ?? $category?->parent?->item_type_id;
            if ($owner) {
                $data['item_type_id'] = $owner;
            }
        }
        $data = app(MaterialDefaultsService::class)->apply($data, $material);
        if (! isset($data['issue_uom_id']) && isset($data['base_uom_id'])) {
            $data['issue_uom_id'] = $data['base_uom_id'];
        }
        if (isset($data['base_uom_id'])) {
            $data['unit_of_measure'] = UnitOfMeasure::findOrFail($data['base_uom_id'])->code;
        }
        $conversions = [];
        foreach (['purchase', 'issue'] as $kind) {
            $unit = $data[$kind.'_uom_id'] ?? null;
            $base = $data['base_uom_id'] ?? null;
            if (! $unit || ! $base || (int) $unit === (int) $base) {
                continue;
            }
            $factor = $row[ucfirst($kind).' conversion factor'] ?? $material?->uomConversions()->where('from_uom_id', $unit)->value('factor');
            if (! is_numeric($factor) || (float) $factor <= 0) {
                throw ValidationException::withMessages(['uom_conversions' => 'Supply a positive '.$kind.' conversion factor when units differ.']);
            }
            if (isset($conversions[$unit]) && (float) $conversions[$unit]['factor'] !== (float) $factor) {
                throw ValidationException::withMessages(['uom_conversions' => 'The same unit cannot have different conversion factors.']);
            }
            $conversions[$unit] = ['from_uom_id' => $unit, 'factor' => $factor];
        }
        if (array_key_exists('Purchase unit', $row) || array_key_exists('Issue unit', $row)) {
            $data['uom_conversions'] = array_values($conversions);
        }
        $form = new StoreMaterialRequest([], $data, [], [], [], ['REQUEST_METHOD' => 'POST']);
        $form->setContainer(app());
        $rules = $form->rules();
        $rules['material_code'] = ['nullable', 'string', 'max:100', Rule::unique('library_materials', 'material_code')->ignore($material?->id)];
        $validator = Validator::make($data, $rules);
        foreach ($form->after() as $check) {
            $validator->after($check);
        }
        $validated = $validator->validate();
        if (! $material) {
            app(MaterialRegistrationService::class)->create($validated, auth()->id());

            return true;
        }
        // Reuse the exact modal update path, including stock-unit lock, board-history guard, completeness and conversions.
        $update = new UpdateMaterialRequest([], $validated, [], [], [], ['REQUEST_METHOD' => 'POST']);
        $update->setContainer(app())->setValidator($validator);
        app(MaterialController::class)->update($update, $material->id);

        return false;
    }
}
