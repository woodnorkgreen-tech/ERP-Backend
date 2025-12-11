<?php

namespace App\Modules\Projects\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Collection;
use App\Modules\Projects\Models\EnquiryTask;

class MaterialsTemplateExport implements WithMultipleSheets
{
    protected $task;
    protected $enquiry;
    
    public function __construct($taskId)
    {
        $this->task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        $this->enquiry = $this->task->enquiry;
    }
    
    public function sheets(): array
    {
        return [
            new InstructionsSheet(),
            new ProjectInfoSheet($this->enquiry),
            new MaterialsDataSheet(),
        ];
    }
}

/**
 * Instructions Sheet - Read-only guide for users
 */
class InstructionsSheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    public function title(): string
    {
        return 'Instructions';
    }
    
    public function collection()
    {
        return collect([
            ['HOW TO USE THIS TEMPLATE'],
            [''],
            ['STEP 1: Go to "Materials Data" sheet'],
            [''],
            ['STEP 2: Fill in your materials using the Empty Cell Continuation method:'],
            ['  • First row of each element: Fill ALL element columns + first particular'],
            ['  • Additional particulars: LEAVE element columns EMPTY, fill only particular columns'],
            ['  • Start new element: Fill ALL element columns again with new Element ID'],
            [''],
            ['EXAMPLE:'],
            ['Row 1: E001 | stage | Main Stage | production | 6 | 8 | 0.6 | Stage Boards | Pcs | 8 | YES'],
            ['Row 2: [empty cells for element] | Stage Legs | Pcs | 16 | YES'],
            ['Row 3: [empty cells for element] | Stage Screws | Pcs | 32 | YES'],
            ['Row 4: E002 | backdrop | Backdrop 1 | hire | 3 | 4 | 0 | Fabric | Mtrs | 12 | YES'],
            [''],
            ['IMPORTANT RULES:'],
            ['✓ Element ID must be unique (E001, E002, E003...)'],
            ['✓ Each element needs at least 1 particular'],
            ['✓ Use dropdown values where provided'],
            ['✓ Quantities must be greater than 0'],
            ['✓ First data row MUST be an element header'],
            [''],
            ['TIPS:'],
            ['• Copy and paste rows to duplicate similar elements'],
            ['• Use Excel row grouping to collapse/expand elements'],
            ['• Delete sample data before adding your own'],
            ['• Save file before uploading'],
            [''],
            ['FIELD DESCRIPTIONS:'],
            [''],
            ['Element ID: Unique identifier (E001, E002, etc.)'],
            ['Element Type: Type from dropdown (stage, backdrop, skirting, etc.)'],
            ['Element Name: Your custom name for the element'],
            ['Category: production, hire, or outsourced'],
            ['Width/Length/Height: Dimensions in meters (can be 0)'],
            [''],
            ['Particular Description: Name of the material/component'],
            ['Unit: Unit of measurement from dropdown'],
            ['Quantity: Amount needed (must be > 0)'],
            ['Included: YES or NO'],
            ['Notes: Optional additional information'],
        ]);
    }
    
    public function headings(): array
    {
        return [];
    }
    
    public function styles(Worksheet $sheet)
    {
        // Title row
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4788']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        
        // Section headers
        foreach ([3, 10, 16, 19, 27] as $row) {
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
            ]);
        }
        
        // Protect sheet
        $sheet->getProtection()->setSheet(true);
        
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

/**
 * Project Info Sheet - Auto-filled project details (read-only)
 */
class ProjectInfoSheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $enquiry;
    
    public function __construct($enquiry)
    {
        $this->enquiry = $enquiry;
    }
    
    public function title(): string
    {
        return 'Project Info';
    }
    
    public function collection()
    {
        return collect([
            ['PROJECT INFORMATION'],
            [''],
            ['Enquiry Number:', $this->enquiry->enquiry_number ?? 'N/A'],
            ['Project Title:', $this->enquiry->title ?? 'N/A'],
            ['Client Name:', $this->enquiry->client->full_name ?? 'N/A'],
            ['Venue:', $this->enquiry->venue ?? 'N/A'],
            ['Expected Delivery Date:', $this->enquiry->expected_delivery_date ?? 'N/A'],
            [''],
            ['Template Generated:', now()->format('Y-m-d H:i:s')],
            [''],
            ['NOTE: This information is read-only. Fill your materials in the "Materials Data" sheet.'],
        ]);
    }
    
    public function headings(): array
    {
        return [];
    }
    
    public function styles(Worksheet $sheet)
    {
        // Title
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4788']],
        ]);
        
        // Labels (column A)
        $sheet->getStyle('A3:A9')->applyFromArray([
            'font' => ['bold' => true],
        ]);
        
        // Values (column B)
        $sheet->getStyle('B3:B9')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']],
        ]);
        
        // Protect sheet
        $sheet->getProtection()->setSheet(true);
        
        return [];
    }
}

/**
 * Materials Data Sheet - Main data entry area
 */
class MaterialsDataSheet implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle, WithEvents
{
    public function title(): string
    {
        return 'Materials Data';
    }
    
    public function collection()
    {
        // Sample data rows to show format
        return collect([
            // Element 1 with particulars (sample)
            ['E001', 'stage', 'Main Stage (SAMPLE)', 'production', 6, 8, 0.6, 'Stage Boards', 'Pcs', 8, 'YES', 'Delete this sample data'],
            ['', '', '', '', '', '', '', 'Stage Legs', 'Pcs', 16, 'YES', ''],
            ['', '', '', '', '', '', '', 'Stage Screws', 'Pcs', 32, 'YES', ''],
            ['', '', '', '', '', '', '', 'Carpet', 'sqm', 48, 'YES', ''],
            // Element 2 with particulars (sample)
            ['E002', 'backdrop', 'Backdrop 1 (SAMPLE)', 'hire', 3, 4, 0, 'Fabric', 'Mtrs', 12, 'YES', 'Delete this sample data'],
            ['', '', '', '', '', '', '', 'Frame', 'Pcs', 4, 'YES', ''],
            ['', '', '', '', '', '', '', 'Clips', 'Pcs', 20, 'YES', ''],
            // Empty rows for user data
            ['', '', '', '', '', '', '', '', '', '', '', 'Add your data here →'],
            ['', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
    }
    
    public function headings(): array
    {
        return [
            'Element ID',
            'Element Type',
            'Element Name',
            'Category',
            'Width (m)',
            'Length (m)',  
            'Height (m)',
            'Particular Description',
            'Unit',
            'Quantity',
            'Included',
            'Notes'
        ];
    }
    
    public function columnWidths(): array
    {
        return [
            'A' => 12,  // Element ID
            'B' => 15,  // Element Type
            'C' => 25,  // Element Name
            'D' => 12,  // Category
            'E' => 10,  // Width
            'F' => 10,  // Length
            'G' => 10,  // Height
            'H' => 30,  // Particular Description
            'I' => 10,  // Unit
            'J' => 10,  // Quantity
            'K' => 10,  // Included
            'L' => 20,  // Notes
        ];
    }
    
    public function styles(Worksheet $sheet)
    {
        // Header row styling
        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4788']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        
        // Sample data styling
        $sheet->getStyle('A2:L8')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']], // Light yellow
        ]);
        
        // Element header rows (rows with Element ID filled)
        $sheet->getStyle('A2:L2')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']], // Light blue
        ]);
        $sheet->getStyle('A6:L6')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
        ]);
        
        // Borders
        $sheet->getStyle('A1:L200')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);
        
        return [];
    }
    
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                
                // Data validations for dropdowns
                $this->addDataValidations($sheet);
                
                // Freeze header row
                $sheet->freezePane('A2');
            },
        ];
    }
    
    private function addDataValidations(Worksheet $sheet)
    {
        // Get dropdown values from config
        $elementTypes = implode(',', config('materials.element_types', []));
        $categories = implode(',', config('materials.categories', []));
        $units = implode(',', config('materials.units', []));
        $includedOptions = implode(',', config('materials.included_options', []));
        
        // Element Type dropdown (Column B)
        $elementTypeValidation = $sheet->getCell('B2')->getDataValidation();
        $elementTypeValidation->setType(DataValidation::TYPE_LIST);
        $elementTypeValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $elementTypeValidation->setAllowBlank(true);
        $elementTypeValidation->setShowDropDown(true);
        $elementTypeValidation->setFormula1("\"{$elementTypes}\"");
        $elementTypeValidation->setPrompt('Select element type from list');
        
        // Copy validation down
        for ($row = 2; $row <= 200; $row++) {
            $sheet->getCell("B{$row}")->setDataValidation(clone $elementTypeValidation);
        }
        
        // Category dropdown (Column D)
        $categoryValidation = $sheet->getCell('D2')->getDataValidation();
        $categoryValidation->setType(DataValidation::TYPE_LIST);
        $categoryValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $categoryValidation->setAllowBlank(true);
        $categoryValidation->setShowDropDown(true);
        $categoryValidation->setFormula1("\"{$categories}\"");
        $categoryValidation->setPrompt('Select category');
        
        for ($row = 2; $row <= 200; $row++) {
            $sheet->getCell("D{$row}")->setDataValidation(clone $categoryValidation);
        }
        
        // Unit dropdown (Column I)
        $unitValidation = $sheet->getCell('I2')->getDataValidation();
        $unitValidation->setType(DataValidation::TYPE_LIST);
        $unitValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $unitValidation->setAllowBlank(false);
        $unitValidation->setShowDropDown(true);
        $unitValidation->setFormula1("\"{$units}\"");
        $unitValidation->setPrompt('Select unit of measurement');
        
        for ($row = 2; $row <= 200; $row++) {
            $sheet->getCell("I{$row}")->setDataValidation(clone $unitValidation);
        }
        
        // Included dropdown (Column K)
        $includedValidation = $sheet->getCell('K2')->getDataValidation();
        $includedValidation->setType(DataValidation::TYPE_LIST);
        $includedValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $includedValidation->setAllowBlank(false);
        $includedValidation->setShowDropDown(true);
        $includedValidation->setFormula1("\"{$includedOptions}\"");
        $includedValidation->setPrompt('Is this included?');
        
        for ($row = 2; $row <= 200; $row++) {
            $sheet->getCell("K{$row}")->setDataValidation(clone $includedValidation);
        }
    }
}
