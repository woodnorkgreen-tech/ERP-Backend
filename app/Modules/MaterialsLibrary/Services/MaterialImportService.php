<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\Workstation;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MaterialImportService
{
    /**
     * Standard columns that map directly to database fields.
     */
    private const STANDARD_COLUMNS = [
        'sku_code' => 'material_code',
        'code' => 'material_code',
        'material_code' => 'material_code',
        'sku' => 'material_code',
        'part_number' => 'material_code',
        'part_no' => 'material_code',
        'item_code' => 'material_code',
        
        'material_item_name' => 'material_name',
        'material_name' => 'material_name',
        'item_name' => 'material_name',
        'name' => 'material_name',
        'description' => 'material_name',
        'item_description' => 'material_name',
        'material' => 'material_name',
        'item' => 'material_name',
        'product_name' => 'material_name',
        'product' => 'material_name',
        
        'category' => 'category',
        'sub_category' => 'subcategory',
        'subcategory' => 'subcategory',
        'sub_cat' => 'subcategory',
        
        'uom' => 'unit_of_measure',
        'unit_of_measure' => 'unit_of_measure',
        'issue_unit' => 'unit_of_measure',
        'unit' => 'unit_of_measure',
        'measure' => 'unit_of_measure',
        
        'unit_cost' => 'unit_cost',
        'cost' => 'unit_cost',
        'cost_per_sqm' => 'unit_cost',
        'cost_per_roll' => 'unit_cost',
        'cost_per_unit' => 'unit_cost',
        'price' => 'unit_cost',
        'unit_price' => 'unit_cost',
        
        'notes' => 'notes',
        'remarks' => 'notes',
        'comment' => 'notes',
        'comments' => 'notes',
    ];

    /**
     * Process the import of an Excel file.
     */
    public function import($file, $workstationId)
    {
        $workstation = Workstation::findOrFail($workstationId);
        
        // Load the spreadsheet
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Get headers (first row)
        $headers = [];
        foreach ($worksheet->getRowIterator(1, 1) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $headers[] = $cell->getValue();
            }
        }
        
        // Map headers to column indices
        $headerMap = [];
        foreach ($headers as $index => $header) {
            if (!empty($header)) {
                $headerMap[$index] = trim($header);
            }
        }

        $results = [
            'total' => 0,
            'success' => 0,
            'errors' => [],
            'updated' => 0,
            'created' => 0
        ];

        DB::beginTransaction();
        try {
            // Iterate through rows starting from row 2
            $rowIndex = 2;
            foreach ($worksheet->getRowIterator(2) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $columnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                    if (isset($headerMap[$columnIndex])) {
                        $rowData[$headerMap[$columnIndex]] = $cell->getValue();
                    }
                }

                // Skip empty rows
                if ($this->isRowEmpty($rowData)) {
                    continue;
                }

                $results['total']++;

                try {
                    $this->processRow($rowData, $workstationId, $results);
                } catch (\Exception $e) {
                    $results['errors'][] = "Row {$rowIndex}: " . $e->getMessage();
                }
                
                $rowIndex++;
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Check if a row is effectively empty.
     */
    private function isRowEmpty($row)
    {
        foreach ($row as $value) {
            if (!empty($value)) return false;
        }
        return true;
    }

    /**
     * Process a single row of data.
     */
    private function processRow($row, $workstationId, &$results)
    {
        $materialData = [
            'workstation_id' => $workstationId,
            'is_active' => true,
            'attributes' => ['attributes' => []], // Initialize JSON structure
        ];

        $attributes = [];

        // Map columns
        foreach ($row as $header => $value) {
            // Replace special chars with underscore, then collapse multiple underscores to single one
            $cleanHeader = preg_replace('/_+/', '_', str_replace([' ', '/', '-'], '_', $header));
            $normalizedHeader = Str::snake(strtolower($cleanHeader));
            
            // Check if it's a standard DB column
            if (isset(self::STANDARD_COLUMNS[$normalizedHeader])) {
                $dbColumn = self::STANDARD_COLUMNS[$normalizedHeader];
                
                // Handle special cases
                if ($dbColumn === 'unit_cost') {
                     // If we already have a cost (e.g. from Cost per Roll) and this is Cost per Sqm, 
                     // we might want to prefer one or store both.
                     // For now, let's update if the value is numeric
                     if (is_numeric($value)) {
                         $materialData[$dbColumn] = $value;
                     }
                } else {
                    // Don't overwrite existing value with empty value (especially for UOM/Issue Unit duplicate cols)
                    if (!empty($value) || !isset($materialData[$dbColumn])) {
                        $materialData[$dbColumn] = $value;
                    }
                }
            } else {
                // It's a dynamic attribute
                if (!in_array($normalizedHeader, ['line_#', 'line', 'line#', 'workstation'])) {
                    $attributes[$normalizedHeader] = $value;
                }
            }
        }

        // Add attributes to data
        $materialData['attributes']['attributes'] = $attributes;

        // Validation: Required fields
        if (empty($materialData['unit_of_measure'])) {
            $materialData['unit_of_measure'] = '-';
        }
        if (empty($materialData['material_code'])) {
            throw new \Exception("Missing SKU/Material Code");
        }
        if (empty($materialData['material_name'])) {
            throw new \Exception("Missing Material Name");
        }

        // Update or Create
        $material = LibraryMaterial::where('material_code', $materialData['material_code'])->first();

        if ($material) {
            $materialData['updated_by'] = auth()->id();
            $material->update($materialData);
            $results['updated']++;
        } else {
            $materialData['created_by'] = auth()->id();
            $materialData['updated_by'] = auth()->id();
            LibraryMaterial::create($materialData);
            $results['created']++;
        }
        
        $results['success']++;
    }
}
