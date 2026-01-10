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
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Collection;

class MaterialsTemplateExport implements WithMultipleSheets
{
    protected $task;
    protected $enquiry;
    protected $referenceData;
    
    public function __construct($taskId)
    {
        $this->task = EnquiryTask::with('enquiry.client')->findOrFail($taskId);
        $this->enquiry = $this->task->enquiry;
        
        // Prepare centralized reference data
        $this->referenceData = [
            'element_types' => config('materials.element_types', [
                'stage', 'backdrop', 'skirting', 'flooring', 'trussing', 'décor', 
                'lighting', 'sound', 'chairs', 'tables', 'signage', 'custom'
            ]),
            'categories' => config('materials.categories', [
                'production', 'hire', 'outsourced'
            ]),
            'units' => config('materials.units', [
                'Pcs', 'Ltrs', 'Mtrs', 'sqm', 'Pks', 'Kgs', 'custom', 'set', 'days', 'hrs'
            ]),
            'boolean_options' => ['YES', 'NO']
        ];
    }
    
    public function sheets(): array
    {
        return [
            new InstructionsSheet(),
            new ProjectInfoSheet($this->enquiry),
            new ReferencesSheet($this->referenceData), // New dynamic reference sheet
            new MaterialsDataSheet($this->referenceData), // Pass data to main sheet
        ];
    }
}

/**
 * References Sheet - Hidden sheet storing dropdown options
 */
class ReferencesSheet implements FromCollection, WithTitle, ShouldAutoSize
{
    protected $data; // Renamed from $options to avoid conflict

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function title(): string
    {
        return 'Reference Data';
    }

    public function collection()
    {
        // Transpose data into columns for easier named range reference
        $maxRows = max(
            count($this->data['element_types']),
            count($this->data['categories']),
            count($this->data['units']),
            count($this->data['boolean_options'])
        );

        $rows = [['Element Types', 'Categories', 'Units', 'Yes/No']]; // Headers

        for ($i = 0; $i < $maxRows; $i++) {
            $rows[] = [
                $this->data['element_types'][$i] ?? null,
                $this->data['categories'][$i] ?? null,
                $this->data['units'][$i] ?? null,
                $this->data['boolean_options'][$i] ?? null,
            ];
        }

        return collect($rows);
    }
}

/**
 * Instructions Sheet - visual guide
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
            ['MATERIALS UPLOAD TEMPLATE GUIDE'],
            [''],
            ['1. STRUCTURE'],
            ['This template uses a parent-child structure:'],
            ['• PARENT ROWS (Dark Blue): Define the Element (e.g., "Main Stage")'],
            ['• CHILD ROWS (Light Blue): Define specific materials needed for that element (e.g., "Plywood", "Screws")'],
            [''],
            ['2. HOW TO FIll'],
            ['Column A (Element ID) controls the structure.'],
            ['• NEW ELEMENT: Enter a unique ID (E.g., E001) in Column A. Fill Columns B-G.'],
            ['• ADD MATERIALS: Leave Column A EMPTY. Fill details in Columns H-L.'],
            [''],
            ['3. EXAMPLE'],
            ['| ID   | Type  | Name       | ... | Material       | Qty | Unit |'],
            ['| E001 | stage | Main Stage | ... | Plywood 18mm | 10  | Pcs  | (Parent Row)'],
            ['|      |       |            | ... | 2x4 Timber   | 20  | Pcs  | (Child Row)'],
            ['|      |       |            | ... | Black Paint  | 5   | Ltrs | (Child Row)'],
            ['| E002 | sound | PA System  | ... | Speakers     | 2   | Set  | (New Element)'],
            [''],
            ['4. IMPORTANT NOTES'],
            ['• Green Headers = Element Details (Fill once per element)'],
            ['• Orange Headers = Material Details (Fill for every item)'],
            ['• Do not delete or reorder columns.'],
            ['• Use the provided dropdown lists for Units and Categories.'],
        ]);
    }
    
    public function headings(): array
    {
        return [];
    }
    
    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C5282']], // Dark Blue
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        
        $sheet->getStyle('A3:A19')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '2B6CB0']],
        ]);

        return [];
    }
}

/**
 * Project Info Sheet - Read-only context
 */
class ProjectInfoSheet implements FromCollection, WithStyles, WithTitle, ShouldAutoSize
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
            ['PROJECT CONTEXT'],
            ['Enquiry #', $this->enquiry->enquiry_number ?? 'N/A'],
            ['Project', $this->enquiry->title ?? 'N/A'],
            ['Client', $this->enquiry->client->full_name ?? 'N/A'],
            ['Generated', now()->format('d M Y, h:i A')],
        ]);
    }

    public function styles(Worksheet $sheet)
    {
         $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C5282']],
        ]);
        $sheet->getStyle('A2:A5')->applyFromArray(['font' => ['bold' => true]]);
        
        return [];
    }
}

/**
 * Materials Data Sheet - Main Input
 */
class MaterialsDataSheet implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle, WithEvents
{
    protected $referenceData; // Renamed from $options to avoid conflict

    public function __construct(array $data)
    {
        $this->referenceData = $data;
    }

    public function title(): string
    {
        return 'Materials Data';
    }
    
    public function collection()
    {
        return collect([
            // Example Row 1: Parent
            ['E001', 'stage', 'Main Stage Platform', 'production', 6, 8, 0.6, 'Stage Deck 8x4', 'Pcs', 4, 'YES', 'Example Data - Delete Me'],
            // Example Row 2: Child
            ['', '', '', '', '', '', '', 'Stage Legs 60cm', 'Pcs', 16, 'YES', ''],
            // Example Row 3: Child
            ['', '', '', '', '', '', '', 'Velcro Skirting', 'Mtrs', 20, 'YES', ''],
            // Spacer
            ['', '', '', '', '', '', '', '', '', '', '', ''],
            // Example Row 4: New Parent
            ['E002', 'sound', 'DJ Booth Monitor', 'hire', 0, 0, 0, 'Active Monitor Speaker', 'Pcs', 2, 'YES', ''],
             // Empty rows for start
            ['', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', ''],
            ['', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
    }
    
    public function headings(): array
    {
        return [
            'Element ID', 'Type', 'Element Name', 'Category', 'W (m)', 'L (m)', 'H (m)', // Element Group
            'Material / Particular', 'Unit', 'Qty', 'Included', 'Notes' // Material Group
        ];
    }
    
    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 15, 'C' => 25, 'D' => 15, 'E' => 8, 'F' => 8, 'G' => 8, // Element
            'H' => 35, 'I' => 10, 'J' => 10, 'K' => 10, 'L' => 25 // Material
        ];
    }
    
    public function styles(Worksheet $sheet)
    {
        // 1. HEADER STYLING
        // Element Columns (A-G): Greenish Blue
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '319795']], // Teal-500
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        
        // Material Columns (H-L): Warm Orange
        $sheet->getStyle('H1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DD6B20']], // Orange-500
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // 2. DATA BORDERS
        $sheet->getStyle('A1:L100')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        ]);

        // 3. VISUAL SEPARATION COLUMN
        // Add a thick border between Element (G) and Material (H) columns
        $sheet->getStyle('G1:G100')->applyFromArray([
            'borders' => ['right' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'A0AEC0']]],
        ]);

        return [];
    }
    
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2'); // Freeze header
                
                // DATA VALIDATION USING REFERENCE SHEET
                // Row count in reference sheet
                $refRowCount = 50; 

                // 1. Element Type (Column B) -> Reference Data!A2:A$refRowCount
                $this->addValidation($sheet, 'B2:B500', "'Reference Data'!\$A\$2:\$A\$$refRowCount", 'Select Type');

                // 2. Category (Column D) -> Reference Data!B2:B$refRowCount
                $this->addValidation($sheet, 'D2:D500', "'Reference Data'!\$B\$2:\$B\$$refRowCount", 'Select Category');

                // 3. Unit (Column I) -> Reference Data!C2:C$refRowCount
                $this->addValidation($sheet, 'I2:I500', "'Reference Data'!\$C\$2:\$C\$$refRowCount", 'Select Unit');

                // 4. Included (Column K) -> Reference Data!D2:D$refRowCount
                $this->addValidation($sheet, 'K2:K500', "'Reference Data'!\$D\$2:\$D\$$refRowCount", 'Yes/No');
            },
        ];
    }

    private function addValidation($sheet, $range, $formula, $prompt)
    {
        $validation = $sheet->getCell(explode(':', $range)[0])->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setFormula1($formula);
        $validation->setShowInputMessage(true);
        $validation->setPromptTitle('Valid Option');
        $validation->setPrompt($prompt);
        
        $sheet->setDataValidation($range, $validation);
    }
}
