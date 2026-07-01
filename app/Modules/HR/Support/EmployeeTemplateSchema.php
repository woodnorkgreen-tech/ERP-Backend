<?php

namespace App\Modules\HR\Support;

use Illuminate\Support\Str;

/**
 * Single source of truth for the bulk employee Excel template (download → edit → reupload).
 *
 * Both EmployeesTemplateExport (writes the header row + maps each Employee to a row)
 * and EmployeesTemplateImport (reads the slugged header row back) reference this class,
 * so the columns can never drift apart.
 *
 * IMPORTANT: header labels are deliberately free of parentheses/slashes so that
 * Maatwebsite's WithHeadingRow slug (Str::slug(label, '_')) stays stable and predictable.
 */
class EmployeeTemplateSchema
{
    /**
     * Ordered map of internal field key => human header label.
     * Order here is the column order in the spreadsheet.
     */
    public const FIELDS = [
        // --- Anchors (how a reuploaded row is matched to an existing employee) ---
        'db_id'                          => 'System ID',          // locked, system-managed, primary anchor
        'employee_id'                    => 'Employee No',        // business key, secondary anchor + unique on create
        'email'                          => 'Email',              // unique identifier, tertiary anchor + unique on create

        // --- Core profile ---
        'first_name'                     => 'First Name',
        'last_name'                      => 'Last Name',
        'phone'                          => 'Phone',
        'department'                     => 'Department',          // department NAME (resolved to department_id on import)
        'position'                       => 'Position',
        'manager_employee_no'            => 'Manager Employee No', // manager's Employee No (resolved to manager_id on import)
        'status'                         => 'Status',
        'employment_type'                => 'Employment Type',
        'hire_date'                      => 'Hire Date',
        'probation_end_date'             => 'Probation End Date',
        'is_on_probation'                => 'On Probation',
        'contract_end_date'              => 'Contract End Date',
        'date_of_birth'                  => 'Date of Birth',
        'address'                        => 'Address',

        // --- Statutory / identity ---
        'id_number'                      => 'ID Number',
        'kra_pin'                        => 'KRA PIN',
        'nssf_id'                        => 'NSSF No',
        'nhif_id'                        => 'NHIF No',
        'hikvision_id'                   => 'Biometric ID',
        'statutory_exemptions'           => 'Statutory Exemptions', // comma-separated list

        // --- Sensitive: salary/bank (only exported/imported with employee.view_salary) ---
        'salary'                         => 'Salary',
        'payment_method'                 => 'Payment Method',
        'bank_name'                      => 'Bank Name',
        'bank_branch'                    => 'Bank Branch',
        'bank_code'                      => 'Bank Code',
        'account_number'                 => 'Account Number',

        // --- Emergency contact (flattened from the emergency_contact JSON column) ---
        'emergency_contact_name'         => 'Emergency Contact Name',
        'emergency_contact_relationship' => 'Emergency Contact Relationship',
        'emergency_contact_phone'        => 'Emergency Contact Phone',

        // --- Performance ---
        'performance_rating'             => 'Performance Rating',
        'last_review_date'               => 'Last Review Date',
    ];

    /**
     * Fields that carry salary/bank data. Blanked on export and ignored on import
     * for users who lack the employee.view_salary permission.
     */
    public const SENSITIVE_FIELDS = [
        'salary', 'payment_method', 'bank_name', 'bank_branch', 'bank_code', 'account_number',
    ];

    /** Fields that must never be written by the importer (read-only anchors/derived). */
    public const READ_ONLY_FIELDS = ['db_id'];

    /**
     * Canonical enum options — the SINGLE source of truth shared by the export
     * (dropdown lists) and the import (Rule::in validation), so a value that the
     * spreadsheet offers can never be one the importer rejects, and vice-versa.
     */
    public const STATUS_OPTIONS          = ['active', 'inactive', 'terminated', 'on-leave'];
    public const EMPLOYMENT_TYPE_OPTIONS = ['full-time', 'part-time', 'contract', 'intern'];
    public const PAYMENT_METHOD_OPTIONS  = ['bank', 'mpesa', 'mobile_money', 'cash', 'cheque'];
    public const YES_NO_OPTIONS          = ['Yes', 'No'];
    public const RELATIONSHIP_OPTIONS    = ['Spouse', 'Parent', 'Child', 'Sibling', 'Friend', 'Relative', 'Guardian', 'Other'];

    /** Fields written/read as YYYY-MM-DD dates (date validation + format on export). */
    public const DATE_FIELDS = [
        'hire_date', 'probation_end_date', 'contract_end_date', 'date_of_birth', 'last_review_date',
    ];

    /**
     * Fields backed by a fixed dropdown list => its canonical options.
     * Department and Manager are dynamic (depend on live data) and are wired up
     * separately by the exporter; everything here is static.
     *
     * @return array<string,string[]>
     */
    public static function staticDropdowns(): array
    {
        return [
            'status'                         => self::STATUS_OPTIONS,
            'employment_type'                => self::EMPLOYMENT_TYPE_OPTIONS,
            'payment_method'                 => self::PAYMENT_METHOD_OPTIONS,
            'is_on_probation'                => self::YES_NO_OPTIONS,
            'emergency_contact_relationship' => self::RELATIONSHIP_OPTIONS,
        ];
    }

    /**
     * The spreadsheet column letter (A, B, C…) a field occupies, derived from its
     * position in FIELDS so the column order stays defined in exactly one place.
     */
    public static function columnLetter(string $field): ?string
    {
        $index = array_search($field, array_keys(self::FIELDS), true);

        return $index === false
            ? null
            : \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    }

    /**
     * Ordered list of header labels for the export heading row.
     *
     * @return string[]
     */
    public static function headers(): array
    {
        return array_values(self::FIELDS);
    }

    /**
     * Reverse map: the slug Maatwebsite produces for each header (WithHeadingRow)
     * back to our internal field key. e.g. 'employee_no' => 'employee_id'.
     *
     * @return array<string,string>
     */
    public static function headerKeyToField(): array
    {
        $map = [];
        foreach (self::FIELDS as $field => $label) {
            $map[Str::slug($label, '_')] = $field;
        }
        return $map;
    }
}
