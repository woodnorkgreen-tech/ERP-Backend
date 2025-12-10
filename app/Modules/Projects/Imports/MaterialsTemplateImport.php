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
            $this->processRow($rowNumber, $row);
            $rowNumber++;
        }
        
        // Save the last element if exists
        if ($this->currentElement !== null) {
            $this->saveCurrentElement($rowNumber);
        }
    }
    
    private function processRow($rowNumber, $row)
    {
        // Convert row to array with expected keys
        $rowData = [
            'element_id' => trim($row['element_id'] ?? ''),
            'element_type' => trim($row['element_type'] ?? ''),
            'element_name' => trim($row['element_name'] ?? ''),
            'category' => trim($row['category'] ?? ''),
            'width' => $row['width_m'] ?? '',
            'length' => $row['length_m'] ?? '',
            'height' => $row['height_m'] ?? '',
            'particular_description' => trim($row['particular_description'] ?? ''),
            'unit' => trim($row['unit'] ?? ''),
            'quantity' => $row['quantity'] ?? '',
            'included' => strtoupper(trim($row['included'] ?? '')),
            'notes' => trim($row['notes'] ?? ''),
        ];
        
        // Check if this row starts a new element (has Element ID)
        $hasElementId = !empty($rowData['element_id']);
        
        if ($hasElementId) {
            // Save previous element before starting new one
            if ($this->currentElement !== null) {
                $this->saveCurrentElement($rowNumber);
            }
            
            // Validate element header
            $elementValidation = $this->validateElementHeader($rowNumber, $rowData);
            
            if (!$elementValidation['valid']) {
                // Skip this element if header is invalid
                $this->currentElement = null;
                $this->currentElementId = null;
                return;
            }
            
            // Start new element
            $this->currentElementId = $rowData['element_id'];
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
        if (!empty($rowData['particular_description'])) {
            if ($this->currentElement === null) {
                $this->parent->addError($rowNumber, "Particular found without element header. Fill element columns first.");
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
            'element_type' => 'Element Type',
            'element_name' => 'Element Name',
            'category' => 'Category',
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (empty($rowData[$field])) {
                $this->parent->addError($rowNumber, "Missing required field: {$label}");
                $valid = false;
            }
        }
        
        // Validate category
        $validCategories = ['production', 'hire', 'outsourced'];
        if (!empty($rowData['category']) && !in_array(strtolower($rowData['category']), $validCategories)) {
            $this->parent->addError($rowNumber, "Invalid category: '{$rowData['category']}'. Must be one of: production, hire, outsourced");
            $valid = false;
        }
        
        // Validate element type (allow custom types but warn)
        $knownTypes = ['stage', 'backdrop', 'skirting', 'flooring', 'trussing', 'décor', 'lighting', 'sound', 'chairs', 'tables', 'signage', 'custom'];
        if (!empty($rowData['element_type']) && !in_array(strtolower($rowData['element_type']), $knownTypes)) {
            $this->parent->addWarning($rowNumber, "Unknown element type: '{$rowData['element_type']}'. Will be treated as custom type.");
        }
        
        // Validate dimensions are numeric
        foreach (['width', 'length', 'height'] as $dim) {
            if (!empty($rowData[$dim]) && !is_numeric($rowData[$dim])) {
                $this->parent->addWarning($rowNumber, ucfirst($dim) . " is not numeric. Will be set to 0.");
            }
        }
        
        return ['valid' => $valid];
    }
    
    private function validateParticular($rowNumber, $rowData)
    {
        $valid = true;
        
        // Required fields
        if (empty($rowData['particular_description'])) {
            $this->parent->addError($rowNumber, "Particular description is required");
            $valid = false;
        }
        
        if (empty($rowData['unit'])) {
            $this->parent->addError($rowNumber, "Unit is required for particular");
            $valid = false;
        }
        
        if (empty($rowData['quantity']) || !is_numeric($rowData['quantity']) || floatval($rowData['quantity']) <= 0) {
            $this->parent->addError($rowNumber, "Quantity must be a number greater than 0");
            $valid = false;
        }
        
        // Validate 'included' field
        if (!in_array($rowData['included'], ['YES', 'NO', ''])) {
            $this->parent->addWarning($rowNumber, "Invalid 'Included' value. Must be YES or NO. Defaulting to YES.");
        }
        
        // Validate unit (allow custom units but warn)
        $knownUnits = ['pcs', 'ltrs', 'mtrs', 'sqm', 'pks', 'kgs', 'custom'];
        if (!empty($rowData['unit']) && !in_array(strtolower($rowData['unit']), $knownUnits)) {
            $this->parent->addWarning($rowNumber, "Unknown unit: '{$rowData['unit']}'. Will be accepted as custom unit.");
        }
        
        return ['valid' => $valid];
    }
    
    private function saveCurrentElement($rowNumber)
    {
        // Validate: Element must have at least 1 particular
        if (empty($this->currentElement['particulars'])) {
            $this->parent->addError($this->currentElement['row_number'], "Element '{$this->currentElement['id']}' has no particulars/materials");
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
