<?php

namespace App\Modules\HR\Exports;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\EmployeeTemplateSchema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports the editable employee roster as an Excel sheet for bulk edit-and-reupload.
 *
 * First-principles goal: make an inconsistent cell *impossible to enter* rather than
 * catching it after upload. Every constrained column ships as a real Excel dropdown,
 * dates as date cells, numbers as bounded numeric cells — all sourced from
 * EmployeeTemplateSchema so the spreadsheet and the importer can never disagree.
 *
 * - Only rows the current user may access are exported (Employee::accessibleByUser()).
 * - Salary/bank columns are blanked unless the user holds employee.view_salary, so the
 *   download never leaks compensation data and a re-upload can't silently touch it.
 * - The "System ID" column is the stable anchor used by EmployeesTemplateImport to match
 *   a reuploaded row back to its record. It is greyed + flagged read-only here; editing
 *   it is already a hard error on import.
 */
class EmployeesTemplateExport implements FromCollection, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithEvents
{
    /** Extra blank rows below the data that still carry dropdowns, so users can add staff. */
    private const SPARE_ROWS = 300;

    /** Worksheet that holds the dropdown option lists; hidden from the user. */
    private const LISTS_SHEET = 'Lists';

    public function __construct(private bool $canViewSalary)
    {
    }

    public function title(): string
    {
        return 'Employees';
    }

    public function headings(): array
    {
        return EmployeeTemplateSchema::headers();
    }

    public function collection()
    {
        return Employee::query()
            ->accessibleByUser()
            ->with(['department:id,name', 'manager:id,employee_id'])
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * Map one Employee to a spreadsheet row, in EmployeeTemplateSchema::FIELDS order.
     */
    public function map($employee): array
    {
        $emergency = is_array($employee->emergency_contact) ? $employee->emergency_contact : [];
        $exemptions = is_array($employee->statutory_exemptions) ? $employee->statutory_exemptions : [];

        $values = [
            'db_id'                          => $employee->id,
            'employee_id'                    => $employee->employee_id,
            'email'                          => $employee->email,
            'first_name'                     => $employee->first_name,
            'last_name'                      => $employee->last_name,
            'phone'                          => $employee->phone,
            'department'                     => $employee->department?->name,
            'position'                       => $employee->position,
            'manager_employee_no'            => $employee->manager?->employee_id,
            'status'                         => $employee->status,
            'employment_type'                => $employee->employment_type,
            'hire_date'                      => $this->date($employee->hire_date),
            'probation_end_date'             => $this->date($employee->probation_end_date),
            'is_on_probation'                => $employee->is_on_probation ? 'Yes' : 'No',
            'contract_end_date'              => $this->date($employee->contract_end_date),
            'date_of_birth'                  => $this->date($employee->date_of_birth),
            'address'                        => $employee->address,
            'id_number'                      => $employee->id_number,
            'kra_pin'                        => $employee->kra_pin,
            'nssf_id'                        => $employee->nssf_id,
            'nhif_id'                        => $employee->nhif_id,
            'hikvision_id'                   => $employee->hikvision_id,
            'statutory_exemptions'           => implode(', ', $exemptions),
            'salary'                         => $employee->salary,
            'payment_method'                 => $employee->payment_method,
            'bank_name'                      => $employee->bank_name,
            'bank_branch'                    => $employee->bank_branch,
            'bank_code'                      => $employee->bank_code,
            'account_number'                 => $employee->account_number,
            'emergency_contact_name'         => $emergency['name'] ?? null,
            'emergency_contact_relationship' => $emergency['relationship'] ?? null,
            'emergency_contact_phone'        => $emergency['phone'] ?? null,
            'performance_rating'             => $employee->performance_rating,
            'last_review_date'               => $this->date($employee->last_review_date),
        ];

        // Blank sensitive columns for users without salary visibility.
        if (!$this->canViewSalary) {
            foreach (EmployeeTemplateSchema::SENSITIVE_FIELDS as $field) {
                $values[$field] = null;
            }
        }

        // Emit in the canonical column order.
        return array_map(fn ($field) => $values[$field] ?? null, array_keys(EmployeeTemplateSchema::FIELDS));
    }

    /**
     * After the rows are written, decorate the sheet with the validation that enforces
     * consistency: dropdowns, date cells, numeric bounds, header styling, locked anchor.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $this->decorate($event->sheet->getDelegate());
            },
        ];
    }

    private function decorate(Worksheet $sheet): void
    {
        $firstRow = 2; // row 1 is the header
        $lastRow = max($sheet->getHighestRow(), $firstRow) + self::SPARE_ROWS;

        $this->styleHeader($sheet);
        $ranges = $this->buildListsSheet($sheet->getParent());

        // Dropdowns: static enums + dynamic department/manager.
        foreach ($ranges as $field => $listFormula) {
            if ($letter = EmployeeTemplateSchema::columnLetter($field)) {
                $this->applyListValidation($sheet, "{$letter}{$firstRow}:{$letter}{$lastRow}", $listFormula);
            }
        }

        // Date cells: YYYY-MM-DD format + a date picker that rejects junk.
        foreach (EmployeeTemplateSchema::DATE_FIELDS as $field) {
            if ($letter = EmployeeTemplateSchema::columnLetter($field)) {
                $range = "{$letter}{$firstRow}:{$letter}{$lastRow}";
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                $this->applyDateValidation($sheet, $range);
            }
        }

        // Performance rating: 0–5 only.
        if ($letter = EmployeeTemplateSchema::columnLetter('performance_rating')) {
            $this->applyDecimalValidation(
                $sheet,
                "{$letter}{$firstRow}:{$letter}{$lastRow}",
                DataValidation::OPERATOR_BETWEEN,
                '0',
                '5',
                'Rating must be a number between 0 and 5.'
            );
        }

        // Salary: never negative (only present when the caller may see salary).
        if ($this->canViewSalary && ($letter = EmployeeTemplateSchema::columnLetter('salary'))) {
            $this->applyDecimalValidation(
                $sheet,
                "{$letter}{$firstRow}:{$letter}{$lastRow}",
                DataValidation::OPERATOR_GREATERTHANOREQUAL,
                '0',
                null,
                'Salary cannot be negative.'
            );
        }

        $this->lockSystemIdColumn($sheet, $firstRow, $lastRow);
    }

    /**
     * Write each option list onto a hidden sheet and return field => range formula
     * (e.g. 'status' => "Lists!$A$2:$A$5") for wiring up the dropdowns.
     *
     * @return array<string,string>
     */
    private function buildListsSheet(Spreadsheet $spreadsheet): array
    {
        $lists = $spreadsheet->createSheet();
        $lists->setTitle(self::LISTS_SHEET);

        // Static enums first, then the live department names and manager numbers.
        $columns = EmployeeTemplateSchema::staticDropdowns();
        $columns['department'] = Department::query()->orderBy('name')->pluck('name')
            ->filter()->values()->all();
        $columns['manager_employee_no'] = Employee::query()->whereNotNull('employee_id')
            ->orderBy('employee_id')->pluck('employee_id')->filter()->values()->all();

        $ranges = [];
        $colIndex = 1;

        foreach ($columns as $field => $options) {
            $letter = Coordinate::stringFromColumnIndex($colIndex);
            $colIndex++;

            if (empty($options)) {
                continue; // nothing to pick from (e.g. no departments yet) → no dropdown
            }

            $lists->setCellValue("{$letter}1", $field); // header, for readability if unhidden
            $row = 2;
            foreach ($options as $option) {
                $lists->setCellValueExplicit("{$letter}{$row}", (string) $option, DataType::TYPE_STRING);
                $row++;
            }

            $ranges[$field] = sprintf('%s!$%s$2:$%s$%d', self::LISTS_SHEET, $letter, $letter, $row - 1);
        }

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        return $ranges;
    }

    private function applyListValidation(Worksheet $sheet, string $range, string $listFormula): void
    {
        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)      // PhpSpreadsheet inverts this on write → arrow IS shown
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Invalid value')
            ->setError('Pick one of the values from the dropdown list.')
            ->setPromptTitle('Choose from the list')
            ->setPrompt('Select a value using the dropdown arrow.')
            ->setFormula1($listFormula);

        $sheet->setDataValidation($range, $dv);
    }

    private function applyDateValidation(Worksheet $sheet, string $range): void
    {
        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_DATE)
            ->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Invalid date')
            ->setError('Enter a real date in YYYY-MM-DD format.')
            ->setPromptTitle('Date (YYYY-MM-DD)')
            ->setPrompt('Type a date like 2025-01-31.')
            ->setFormula1('DATE(1900,1,1)');

        $sheet->setDataValidation($range, $dv);
    }

    private function applyDecimalValidation(
        Worksheet $sheet,
        string $range,
        string $operator,
        string $formula1,
        ?string $formula2,
        string $message
    ): void {
        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_DECIMAL)
            ->setOperator($operator)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Invalid number')
            ->setError($message)
            ->setPromptTitle('Number')
            ->setPrompt($message)
            ->setFormula1($formula1);

        if ($formula2 !== null) {
            $dv->setFormula2($formula2);
        }

        $sheet->setDataValidation($range, $dv);
    }

    private function styleHeader(Worksheet $sheet): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count(EmployeeTemplateSchema::FIELDS));
        $style = $sheet->getStyle("A1:{$lastColumn}1");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType('solid')->getStartColor()->setRGB('1F2937');
        $sheet->freezePane('A2');
    }

    /**
     * The System ID anchor is system-managed. We can't truly lock one column without
     * protecting the whole sheet (which would block adding rows), so we grey it out and
     * attach a do-not-edit prompt; the importer already rejects an edited/unknown ID.
     */
    private function lockSystemIdColumn(Worksheet $sheet, int $firstRow, int $lastRow): void
    {
        $letter = EmployeeTemplateSchema::columnLetter('db_id');
        if (! $letter) {
            return;
        }

        $range = "{$letter}{$firstRow}:{$letter}{$lastRow}";
        $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle($range)->getFont()->getColor()->setRGB('6B7280');

        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_CUSTOM)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowInputMessage(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('System-managed column')
            ->setError('Do not change the System ID. It links this row to its record.')
            ->setPromptTitle('Do not edit')
            ->setPrompt('System ID is managed automatically. Leave it as-is.')
            ->setFormula1('FALSE'); // any manual entry is rejected

        $sheet->setDataValidation($range, $dv);
    }

    private function date($value): ?string
    {
        return $value ? $value->format('Y-m-d') : null;
    }
}
