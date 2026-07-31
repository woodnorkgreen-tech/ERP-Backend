<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeStagingRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class EmployeeStagingService
{
    /**
     * Known column header aliases mapped to internal normalized fields.
     */
    private const ALIAS_MAP = [
        'email' => ['email id', 'email', 'e-mail', 'email address', 'work email', 'mail'],
        'employee_id' => ['employee no', 'employee id', 'emp no', 'staff no', 'payroll no', 'emp id'],
        'full_name' => ['first name', 'name', 'full name', 'employee name', 'staff name'],
        'id_number' => ['id/passport no', 'id/passport', 'id number', 'national id', 'id no', 'passport no'],
        'nhif_id' => ['shif/sha no', 'shif/sha', 'shif no', 'sha no', 'nhif', 'nhif no', 'nhif id', 'shif'],
        'nssf_id' => ['nssf', 'nssf no', 'nssf id', 'nssf number'],
        'kra_pin' => ['kra pin', 'kra', 'tax pin', 'pin', 'kra_pin'],
        'hire_date' => ['employment date', 'hire date', 'date of employment', 'date employed', 'start date'],
        'position' => ['job title', 'position', 'designation', 'role', 'title'],
        'department_name' => ['department', 'dept', 'dept name'],
        'phone' => ['contact no personal', 'contact no', 'phone', 'phone number', 'mobile', 'personal contact', 'telephone'],
        'gender' => ['gender', 'sex'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date'],
        'address' => ['address', 'residence', 'home address'],
        'salary' => ['salary', 'basic salary', 'gross salary', 'pay'],
        'bank_name' => ['bank name', 'bank'],
        'bank_branch' => ['bank branch', 'branch'],
        'bank_code' => ['bank code'],
        'account_number' => ['account number', 'account no', 'bank account', 'bank account no', 'acc no'],
        'emergency_contact_name' => ['emergency contact name', 'emergency contact', 'next of kin', 'kin name'],
        'emergency_contact_phone' => ['emergency contact phone', 'emergency phone', 'kin phone', 'kin contact'],
        'emergency_contact_relationship' => ['emergency contact relationship', 'relationship', 'kin relationship'],
    ];

    /**
     * Parse an uploaded Excel/CSV dump file and store rows in employee_staging_records.
     */
    public function importDump(UploadedFile $file, ?int $userId = null): array
    {
        $batchId = 'dump_' . now()->format('Ymd_His') . '_' . Str::random(6);
        $batchName = $file->getClientOriginalName();

        // Read rows from Excel
        $sheets = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray {
            public function array(array $array): array { return $array; }
        }, $file);

        if (empty($sheets)) {
            return [
                'batch_id' => $batchId,
                'batch_name' => $batchName,
                'total_rows' => 0,
                'matched_count' => 0,
                'unmatched_count' => 0,
                'message' => 'Excel file is empty.',
            ];
        }

        $stagedCount = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;

        DB::transaction(function () use ($sheets, $batchId, $batchName, $userId, &$stagedCount, &$matchedCount, &$unmatchedCount) {
            foreach ($sheets as $sheetRows) {
                if (empty($sheetRows)) {
                    continue;
                }

                // Copy sheet rows to avoid mutating original array inside loop
                $rowsToProcess = $sheetRows;
                $headerMap = $this->determineHeaderMapping($rowsToProcess);

                foreach ($rowsToProcess as $row) {
                    if ($this->isEmptyRow($row)) {
                        continue;
                    }

                    $stagedData = $this->extractRowData($row, $headerMap);
                    if (empty($stagedData) || ! $this->isValidEmployeeRow($stagedData)) {
                        continue;
                    }

                    $email = $stagedData['email'] ?? null;
                    $employeeId = $stagedData['employee_id'] ?? null;
                    $idNumber = $stagedData['id_number'] ?? null;

                    // Match against existing employee if possible
                    $matchedEmployee = $this->findMatchingEmployee($email, $employeeId, $idNumber);
                    $matchedId = $matchedEmployee?->id;

                    if ($matchedId) {
                        $matchedCount++;
                    } else {
                        $unmatchedCount++;
                    }

                    EmployeeStagingRecord::create([
                        'batch_id' => $batchId,
                        'batch_name' => $batchName,
                        'email' => $email,
                        'employee_id' => $employeeId,
                        'id_number' => $idNumber,
                        'matched_employee_id' => $matchedId,
                        'staged_data' => $stagedData,
                        'status' => 'staged',
                        'uploaded_by' => $userId,
                    ]);

                    $stagedCount++;
                }
            }
        });

        return [
            'batch_id' => $batchId,
            'batch_name' => $batchName,
            'total_rows' => $stagedCount,
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'message' => "Successfully uploaded dump '{$batchName}': all {$stagedCount} Excel rows imported into the staging repository.",
        ];
    }

    /**
     * Auto-detect header row or apply positional column mapping for header-less spreadsheets.
     */
    private function determineHeaderMapping(array &$rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $firstRow = $rows[0];
        $firstRowLower = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $firstRow);

        // Check if first row contains actual employee data (email, phone, employee code, numeric ID)
        $isDataRow = false;
        foreach ($firstRowLower as $cell) {
            if ($cell === '') {
                continue;
            }
            // Contains email (@)
            if (str_contains($cell, '@')) {
                $isDataRow = true;
                break;
            }
            // Contains Phone number starting with 07, 01, 254, or 7
            $cleanPhone = preg_replace('/[^\d]/', '', $cell);
            if (strlen($cleanPhone) >= 9 && preg_match('/^(07|01|254|7)\d+/', $cleanPhone)) {
                $isDataRow = true;
                break;
            }
            // Contains Employee code format like WNG/ or EMP-
            if (preg_match('/^(wng|emp|staff)[\/-]/i', $cell)) {
                $isDataRow = true;
                break;
            }
        }

        // If it's a data row, do NOT shift rows away! Apply positional column mapping.
        if ($isDataRow) {
            return [
                0 => 'full_name',
                1 => 'employee_id',
                2 => 'id_number',
                3 => 'nhif_id',
                4 => 'nssf_id',
                5 => 'kra_pin',
                6 => 'hire_date',
                7 => 'position',
                8 => 'department_name',
                9 => 'phone',
                10 => 'email',
            ];
        }

        // Check if first row contains at least 2 recognized column header titles
        $headerMatches = 0;
        foreach ($firstRowLower as $cell) {
            foreach (self::ALIAS_MAP as $aliases) {
                if (in_array($cell, $aliases, true)) {
                    $headerMatches++;
                    break;
                }
            }
        }

        if ($headerMatches >= 2) {
            $headers = array_shift($rows);
            return $this->buildHeaderMapping(array_map(fn ($h) => mb_strtolower(trim((string) $h)), $headers));
        }

        // Default positional column mapping for header-less sheets
        return [
            0 => 'full_name',
            1 => 'employee_id',
            2 => 'id_number',
            3 => 'nhif_id',
            4 => 'nssf_id',
            5 => 'kra_pin',
            6 => 'hire_date',
            7 => 'position',
            8 => 'department_name',
            9 => 'phone',
            10 => 'email',
        ];
    }

    /**
     * Map header indexes to internal field keys using alias lookup or sanitized header names.
     */
    private function buildHeaderMapping(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $headerClean = preg_replace('/\s+/', ' ', trim((string) $header));
            if ($headerClean === '') {
                continue;
            }

            $matchedField = null;
            foreach (self::ALIAS_MAP as $field => $aliases) {
                if (in_array($headerClean, $aliases, true)) {
                    $matchedField = $field;
                    break;
                }
            }

            // Map to known field alias OR raw sanitized column name
            $map[$index] = $matchedField ?? Str::slug($headerClean, '_');
        }
        return $map;
    }

    /**
     * Extract normalized and raw field data from a single Excel row.
     */
    private function extractRowData(array $row, array $headerMap): array
    {
        $data = [];

        // 1. Extract mapped fields
        foreach ($headerMap as $colIndex => $field) {
            $rawVal = $row[$colIndex] ?? null;
            if ($rawVal === null) {
                continue;
            }
            $val = trim((string) $rawVal);
            if ($val === '' || strtoupper($val) === 'N/A') {
                continue;
            }

            if ($field === 'full_name') {
                $parts = preg_split('/\s+/', $val);
                if (count($parts) >= 2) {
                    $data['first_name'] = array_shift($parts);
                    $data['last_name'] = implode(' ', $parts);
                } else {
                    $data['first_name'] = $val;
                }
                $data['full_name'] = $val;
            } elseif (in_array($field, ['hire_date', 'date_of_birth'], true)) {
                $parsedDate = $this->parseDate($rawVal);
                $data[$field] = $parsedDate ?? $val;
            } else {
                $data[$field] = $val;
            }
        }

        // 2. Intelligent pattern recognition for shifted/unmapped cells
        foreach ($row as $rawVal) {
            if ($rawVal === null) {
                continue;
            }
            $val = trim((string) $rawVal);
            if ($val === '' || strtoupper($val) === 'N/A') {
                continue;
            }

            // Email fallback
            if (! isset($data['email']) && str_contains($val, '@') && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $data['email'] = mb_strtolower($val);
            }
            // KRA PIN fallback (e.g. A009929937W)
            if (! isset($data['kra_pin']) && preg_match('/^[A-Z]\d{8,9}[A-Z]$/i', $val)) {
                $data['kra_pin'] = strtoupper($val);
            }
            // Phone number fallback
            if (! isset($data['phone'])) {
                $cleanPhone = preg_replace('/[^\d]/', '', $val);
                if (strlen($cleanPhone) >= 9 && preg_match('/^(07|01|254|7)\d+/', $cleanPhone)) {
                    $data['phone'] = $val;
                }
            }
            // Employee ID fallback (e.g. WNG/12, WNG-04)
            if (! isset($data['employee_id']) && preg_match('/^wng[\/-]/i', $val)) {
                $data['employee_id'] = strtoupper($val);
            }
        }

        return $data;
    }

    /**
     * Find an existing employee by email, employee_id, or id_number.
     */
    public function findMatchingEmployee(?string $email, ?string $employeeId = null, ?string $idNumber = null): ?Employee
    {
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emp = Employee::withTrashed()->where('email', mb_strtolower(trim($email)))->first();
            if ($emp) return $emp;
        }

        if ($employeeId && strtoupper(trim($employeeId)) !== 'N/A') {
            $emp = Employee::withTrashed()->where('employee_id', trim($employeeId))->first();
            if ($emp) return $emp;
        }

        if ($idNumber && strtoupper(trim($idNumber)) !== 'N/A') {
            $emp = Employee::withTrashed()->where('id_number', trim($idNumber))->first();
            if ($emp) return $emp;
        }

        return null;
    }

    /**
     * Parse raw Excel dates (serial numbers or string dates).
     */
    private function parseDate(mixed $rawVal): ?string
    {
        if (is_numeric($rawVal)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $rawVal)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return \Carbon\Carbon::parse((string) $rawVal)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if extracted row data represents a valid employee record.
     */
    private function isValidEmployeeRow(array $stagedData): bool
    {
        $name = trim($stagedData['full_name'] ?? $stagedData['first_name'] ?? '');
        $email = trim($stagedData['email'] ?? '');
        $id = trim($stagedData['id_number'] ?? '');
        $phone = trim($stagedData['phone'] ?? '');
        $empCode = trim($stagedData['employee_id'] ?? '');
        $kra = trim($stagedData['kra_pin'] ?? '');

        // Must have at least one valid identifying employee field
        return ! empty($name) || ! empty($email) || ! empty($id) || ! empty($phone) || ! empty($empCode) || ! empty($kra);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $val) {
            if ($val !== null && trim((string) $val) !== '') {
                return false;
            }
        }
        return true;
    }
}
