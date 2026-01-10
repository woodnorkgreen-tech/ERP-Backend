<?php

namespace App\Modules\Projects\Imports;

use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Illuminate\Support\Collection;

class MaterialsTemplateImport implements WithMultipleSheets
{
    private $elements = [];
    private $errors = [];
    private $warnings = [];
    private $taskId;
    
    public function __construct($taskId)
    {
        $this->taskId = $taskId;
    }
    
    public function sheets(): array
    {
        return [
            'Materials Data' => new MaterialsDataImport($this),
        ];
    }
    
    public function addElement($element)
    {
        $this->elements[] = $element;
    }
    
    public function addError($rowNumber, $message)
    {
        $this->errors[] = [
            'row' => $rowNumber,
            'message' => $message,
        ];
    }
    
    public function addWarning($rowNumber, $message)
    {
        $this->warnings[] = [
            'row' => $rowNumber,
            'message' => $message,
        ];
    }
    
    public function getElements()
    {
        return $this->elements;
    }
    
    public function getErrors()
    {
        return $this->errors;
    }
    
    public function getWarnings()
    {
        return $this->warnings;
    }
    
    public function getElementCount()
    {
        return count($this->elements);
    }
    
    public function getMaterialCount()
    {
        $total = 0;
        foreach ($this->elements as $element) {
            $total += count($element['particulars']);
        }
        return $total;
    }
    
    public function getPreviewData()
    {
        return [
            'elements' => $this->elements,
            'stats' => [
                'total_elements' => $this->getElementCount(),
                'total_materials' => $this->getMaterialCount(),
                'total_errors' => count($this->errors),
                'total_warnings' => count($this->warnings),
            ],
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}

/**
 * Import handler for Materials Data sheet
 */
class MaterialsDataImport implements ToCollection, WithHeadingRow
{
    private $parent;
    private $currentElement = null;
    private $currentElementId = null;
    
    public function __construct(MaterialsTemplateImport $parent)
    {
        $this->parent = $parent;
    }
    
    public function collection(Collection $rows)
    {
        $rowNumber = 2; // Start at 2 because of header row
        
        foreach ($rows as $row) {
            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                $rowNumber++;
                continue;
            }

            $this->processRow($rowNumber, $row);
            $rowNumber++;
        }
        
        // Save the last element if exists
        if ($this->currentElement !== null) {
            $this->saveCurrentElement($rowNumber);
        }
    }

    private function isEmptyRow($row)
    {
        // Check if all relevant fields are empty
        return empty($row['element_id']) && 
               empty($row['type']) && empty($row['element_type']) &&
               empty($row['material_particular']) && empty($row['particular_description']); 
    }
    
    private function processRow($rowNumber, $row)
    {
        // Normalizing keys to handle both Old and New Template headers
        // New Template: element_id, type, w_m, material_particular, qty
        // Old Template: element_id, element_type, width_m, particular_description, quantity
        
        $elementId = trim($row['element_id'] ?? '');
        
        $rowData = [
            'element_id' => $elementId,
            'element_type' => trim($row['type'] ?? $row['element_type'] ?? ''),
            'element_name' => trim($row['element_name'] ?? ''),
            'category' => trim($row['category'] ?? ''),
            // Dimensions
            'width' => $row['w_m'] ?? $row['width_m'] ?? 0,
            'length' => $row['l_m'] ?? $row['length_m'] ?? 0,
            'height' => $row['h_m'] ?? $row['height_m'] ?? 0,
            // Particulars
            'particular_description' => trim($row['material_particular'] ?? $row['particular_description'] ?? ''),
            'unit' => trim($row['unit'] ?? ''),
            'quantity' => $row['qty'] ?? $row['quantity'] ?? 0,
            'included' => strtoupper(trim($row['included'] ?? '')),
            'notes' => trim($row['notes'] ?? ''),
        ];
        
        // Check if this row starts a new element (has Element ID)
        $hasElementId = !empty($elementId);
        
        if ($hasElementId) {
            // Save previous element before starting new one
            if ($this->currentElement !== null) {
                $this->saveCurrentElement($rowNumber - 1); // Save associated with previous row
            }
            
            // Validate element header
            $elementValidation = $this->validateElementHeader($rowNumber, $rowData);
            
            if (!$elementValidation['valid']) {
                // If header is invalid, we can't properly start an element.
                // We reset state so we don't attach particulars to a broken element.
                $this->currentElement = null;
                $this->currentElementId = null;
                return; 
            }
            
            // Start new element
            $this->currentElementId = $elementId;
            $this->currentElement = [
                'id' => $this->currentElementId,
                'type' => $rowData['element_type'],
                'name' => $rowData['element_name'],
                'category' => $rowData['category'],
                'dimensions' => [
                    'width' => floatval($rowData['width']),
                    'length' => floatval($rowData['length']),
                    'height' => floatval($rowData['height']),
                ],
                'particulars' => [],
                'row_number' => $rowNumber,
            ];
        }
        
        // Add particular if description exists
        // Note: A row can describe an element AND contain the first particular (which is typical for 1-line elements)
        // OR it can be a child row with just particular details.
        
        if (!empty($rowData['particular_description'])) {
            if ($this->currentElement === null) {
                // Orphaned particular?
                // If this row had an element ID but failed validation, we already returned.
                // If this row has NO element ID and NO current element, it's truly an orphan.
                if (!$hasElementId) {
                    $this->parent->addError($rowNumber, "Found material '{$rowData['particular_description']}' but no Element defined. Please ensure the first row has an Element ID.");
                }
                return;
            }
            
            // Validate particular
            $particularValidation = $this->validateParticular($rowNumber, $rowData);
            
            if ($particularValidation['valid']) {
                $this->currentElement['particulars'][] = [
                    'description' => $rowData['particular_description'],
                    'unit' => $rowData['unit'],
                    'quantity' => floatval($rowData['quantity']),
                    'included' => $rowData['included'] === 'YES',
                    'notes' => $rowData['notes'],
                    'row_number' => $rowNumber,
                ];
            }
        }
    }
    
    private function validateElementHeader($rowNumber, $rowData)
    {
        $valid = true;
        
        // Required fields
        $requiredFields = [
            'element_id' => 'Element ID',
            'element_type' => 'Type', // Updated label
            'element_name' => 'Element Name',
            'category' => 'Category',
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (empty($rowData[$field])) {
                $this->parent->addError($rowNumber, "Missing required field: {$label}");
                $valid = false;
            }
        }
        
        // Validate category using config
        $validCategories = config('materials.categories', ['production', 'hire', 'outsourced']);
        if (!empty($rowData['category']) && !in_array(strtolower($rowData['category']), $validCategories)) {
            $categoriesList = implode(', ', $validCategories);
            $this->parent->addError($rowNumber, "Invalid category: '{$rowData['category']}'. Must be one of: {$categoriesList}");
            $valid = false;
        }
        
        // Validate element type using config (allow custom types but warn)
        $knownTypes = config('materials.element_types', ['stage', 'backdrop', 'skirting', 'flooring', 'trussing', 'décor', 'lighting', 'sound', 'chairs', 'tables', 'signage', 'custom']);
        if (!empty($rowData['element_type']) && !in_array(strtolower($rowData['element_type']), $knownTypes)) {
            $this->parent->addWarning($rowNumber, "Unknown type: '{$rowData['element_type']}'. Will be treated as custom.");
        }
        
        return ['valid' => $valid];
    }
    
    private function validateParticular($rowNumber, $rowData)
    {
        $valid = true;
        
        // Required fields
        if (empty($rowData['particular_description'])) {
            $this->parent->addError($rowNumber, "Material description is required");
            $valid = false;
        }
        
        if (empty($rowData['unit'])) {
            $this->parent->addError($rowNumber, "Unit is required");
            $valid = false;
        }
        
        if (empty($rowData['quantity']) || !is_numeric($rowData['quantity']) || floatval($rowData['quantity']) <= 0) {
            $this->parent->addError($rowNumber, "Qty must be > 0");
            $valid = false;
        }
        
        // Validate unit
        $knownUnits = array_map('strtolower', config('materials.units', ['pcs', 'ltrs', 'mtrs', 'sqm', 'pks', 'kgs', 'custom', 'set', 'days', 'hrs']));
        if (!empty($rowData['unit']) && !in_array(strtolower($rowData['unit']), $knownUnits)) {
            $this->parent->addWarning($rowNumber, "Unknown unit: '{$rowData['unit']}'");
        }
        
        return ['valid' => $valid];
    }
    
    private function saveCurrentElement($rowNumber)
    {
        // Use row number of the element itself for error reporting
        $reportRow = $this->currentElement['row_number'] ?? $rowNumber;

        // Validate: Element must have at least 1 particular
        if (empty($this->currentElement['particulars'])) {
            $this->parent->addError($reportRow, "Element '{$this->currentElement['id']}' has no materials defined.");
            return;
        }
        
        // Add to parent
        $this->parent->addElement($this->currentElement);
        
        // Reset current element
        $this->currentElement = null;
        $this->currentElementId = null;
    }
    
    public function headingRow(): int
    {
        return 1; // Headers are in row 1
    }
}
