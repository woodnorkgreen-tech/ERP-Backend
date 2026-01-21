<?php

namespace App\Modules\Finance\PettyCash\Imports;

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class PettyCashDisbursementImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $service;
    protected $results = [
        'successful' => [],
        'failed' => [],
        'duplicates' => []
    ];
    
    protected $totalRows = 0;
    protected $processedRows = 0;
    private $currentRowData = [];

    public function __construct(PettyCashService $service)
    {
        $this->service = $service;
    }

    public function collection(Collection $rows)
    {
        $this->totalRows = $rows->count();
        
        foreach ($rows as $rowIndex => $row) {
            $this->processRow($row, $rowIndex + 2); // +2 for header + 1-indexed
            $this->processedRows++;
        }
    }

    private function processRow($row, $rowNumber)
    {
        // Convert stdClass to array if needed
        if (is_object($row)) {
            $row = (array) $row;
        }
        
        // Normalize column names to lowercase for case-insensitive matching
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            $normalizedKey = strtolower(str_replace([' ', '_'], '', $key));
            $normalizedRow[$normalizedKey] = $value;
        }
        
        // Map normalized keys to expected keys
        $mappedRow = [];
        $columnMapping = [
            'date' => 'date',
            'datedisbursed' => 'date',
            'disbursementdate' => 'date',
            'receiver' => 'receiver',
            'payee' => 'receiver',
            'account' => 'account',
            'ledger' => 'account',
            'amount' => 'amount',
            'description' => 'description',
            'remarks' => 'description',
            'projectname' => 'project_name',
            'tax' => 'tax',
            'class' => 'classification',
            'classname' => 'classification',
            'category' => 'classification',
            'jobno' => 'job_number',
            'jobnumber' => 'job_number',
            'paymentmethod' => 'payment_method',
            'transactioncode' => 'transaction_code'
        ];
        
        foreach ($normalizedRow as $key => $value) {
            if (isset($columnMapping[$key])) {
                $mappedRow[$columnMapping[$key]] = $value;
            } else {
                $mappedRow[$key] = $value;
            }
        }
        
        // Store current row data for error reporting
        $this->currentRowData = $mappedRow;

        try {
            // Check if this is a completely empty row
            $isEmptyRow = true;
            foreach ($mappedRow as $value) {
                if (!empty(trim(is_array($value) ? json_encode($value) : ($value ?? '')))) {
                    $isEmptyRow = false;
                    break;
                }
            }
            
            // Skip completely empty rows
            if ($isEmptyRow) {
                return;
            }

            // Normalize specific fields before validation to handle variations
            $mappedRow = $this->normalizeMappedRow($mappedRow);

            // Perform validation using rules defined below
            $validator = Validator::make($mappedRow, $this->rules(), $this->customValidationMessages());
            
            if ($validator->fails()) {
                $this->addFailedRow($rowNumber, $validator->errors()->all());
                return;
            }

            // Additional custom validation
            $customErrors = $this->validateRowData($mappedRow);
            if (!empty($customErrors)) {
                if (isset($customErrors['skip_empty_row'])) return;
                $this->addFailedRow($rowNumber, $customErrors);
                return;
            }

            // Parse and validate DATE
            $date = $this->parseDate($mappedRow['date'] ?? null);
            if (!$date) {
                $this->addFailedRow($rowNumber, ['Invalid date format. Could not parse date value']);
                // Continue processing even with date errors
            }

            // Check for duplicates (only if we have a valid date)
            if ($date && $this->isDuplicate($mappedRow, $date)) {
                $this->addDuplicateRow($rowNumber, 'Duplicate transaction found');
                return;
            }

            // Check balance availability (only if amount is present)
            $amount = 0;
            if (isset($mappedRow['amount']) && !empty(trim(is_array($mappedRow['amount']) ? json_encode($mappedRow['amount']) : $mappedRow['amount']))) {
                $amount = (float) str_replace([',', ' '], '', $mappedRow['amount']);
            }
            
            if ($amount > 0 && !$this->service->hasSufficientBalance($amount)) {
                $this->addFailedRow($rowNumber, ['Insufficient petty cash balance']);
                return;
            }

            // Create disbursement (only if we have meaningful data)
            if ($amount > 0 || !empty($mappedRow['receiver'] ?? null)) {
                $disbursementData = $this->prepareDisbursementData($mappedRow, $date);
                $disbursement = $this->service->createDisbursement($disbursementData);
                
                if ($disbursement) {
                    $this->addSuccessfulRow($rowNumber, $disbursement->id);
                } else {
                    $this->addFailedRow($rowNumber, ['Failed to create disbursement']);
                }
            }
            
        } catch (\Exception $e) {
            $this->addFailedRow($rowNumber, ['Unexpected error: ' . $e->getMessage()]);
        }
    }

    private function normalizeMappedRow(array $row): array
    {
        // Trim all string values
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = trim($value);
            }
        }

        // Normalize amount (remove commas and spaces)
        if (isset($row['amount']) && !empty($row['amount'])) {
            $amt = is_array($row['amount']) ? json_encode($row['amount']) : $row['amount'];
            $row['amount'] = str_replace([',', ' ', '$'], '', $amt);
        }

        // Normalize classification
        if (!empty($row['classification'] ?? null)) {
            $val = strtolower($row['classification']);
            $map = [
                'admin' => 'admin',
                'administrative' => 'admin',
                'administration' => 'admin',
                'agencies' => 'agencies',
                'agency' => 'agencies',
                'corporate' => 'agencies',
                'operations' => 'operations',
                'operation' => 'operations',
                'operational' => 'operations',
                'other' => 'other',
                'other expenses' => 'other',
                'miscellaneous' => 'other'
            ];
            
            if (isset($map[$val])) {
                $row['classification'] = $map[$val];
            } else {
                // Try partial match
                foreach (['admin', 'agencies', 'operations', 'other'] as $valid) {
                    if (strpos($val, $valid) !== false) {
                        $row['classification'] = $valid;
                        break;
                    }
                }
            }
        }

        // Normalize tax
        if (!empty($row['tax'] ?? null)) {
            $val = strtolower(str_replace([' ', '_'], '', $row['tax']));
            $map = [
                'etr' => 'etr',
                'yes' => 'etr',
                'noetr' => 'no_etr',
                'no' => 'no_etr'
            ];
            if (isset($map[$val])) {
                $row['tax'] = $map[$val];
            }
        }

        return $row;
    }

    private function parseDate($dateString): ?\DateTime
    {
        if (empty($dateString)) {
            return null;
        }

        // Handle Excel numeric dates (serial date format)
        if (is_numeric($dateString)) {
            // Excel serial date: 1 represents 1900-01-01
            $unixTimestamp = (int)(($dateString - 25569) * 86400);
            if ($unixTimestamp > 0) {
                $date = new \DateTime('@' . $unixTimestamp);
                $date->setTime(0, 0, 0);
                return $date;
            }
        }

        // Try multiple date formats
        $formats = [
            'm/d/Y',    // MM/DD/YYYY
            'd/m/Y',    // DD/MM/YYYY
            'Y/m/d',    // YYYY/MM/DD
            'm-d-Y',    // MM-DD-YYYY
            'd-m-Y',    // DD-MM-YYYY
            'Y-m-d',    // YYYY-MM-DD
            'M j, Y',   // Jan 1, 2025
            'F j, Y',   // January 1, 2025
            'j/m/Y',    // D/M/YYYY
            'j-m-Y',    // D-M-YYYY
            'j/m/y',    // D/M/YY
            'j-m-y',    // D-M-YY
            'd.m.Y',    // DD.MM.YYYY
            'm.d.Y',    // MM.DD.YYYY
            'Y.m.d',    // YYYY.MM.DD
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, trim($dateString));
            if ($date) {
                $date->setTime(0, 0, 0);
                return $date;
            }
        }

        // Try parsing with any format
        try {
            $date = new \DateTime(trim($dateString));
            $date->setTime(0, 0, 0);
            return $date;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function validateRowData($row): array
    {
        // Final check to ensure we don't process empty rows
        $isEmptyRow = true;
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                $isEmptyRow = false;
                break;
            }
        }
        
        if ($isEmptyRow) {
            return ['skip_empty_row' => true];
        }

        return [];
    }

    private function isDuplicate($row, $date): bool
    {
        $receiver = trim(is_array($row['receiver'] ?? '') ? json_encode($row['receiver'] ?? '') : ($row['receiver'] ?? ''));
        $account = trim(is_array($row['account'] ?? '') ? json_encode($row['account'] ?? '') : ($row['account'] ?? ''));
        $amount = 0;
        if (isset($row['amount']) && !empty(trim(is_array($row['amount']) ? json_encode($row['amount']) : $row['amount']))) {
            $amount = (float) str_replace([',', ' '], '', is_array($row['amount']) ? json_encode($row['amount']) : $row['amount']);
        }
        $description = trim(is_array($row['description'] ?? '') ? json_encode($row['description'] ?? '') : ($row['description'] ?? ''));
        $projectName = trim($row['project_name'] ?? '');
        
        // Normalize classification
        $classification = '';
        if (!empty($row['classification'] ?? null)) {
            $classification = strtolower(trim(is_array($row['classification']) ? json_encode($row['classification']) : $row['classification']));
            // Apply the same normalization as in validateRowData
            $classificationMap = [
                'administrative' => 'admin',
                'administration' => 'admin',
                'agency' => 'agencies',
                'corporate' => 'agencies',
                'operation' => 'operations',
                'operational' => 'operations',
                'other expenses' => 'other',
                'miscellaneous' => 'other'
            ];
            
            if (isset($classificationMap[$classification])) {
                $classification = $classificationMap[$classification];
            } else {
                // Try to match partial strings
                $validClassifications = ['admin', 'agencies', 'operations', 'other'];
                foreach ($validClassifications as $valid) {
                    if (strpos($classification, $valid) !== false) {
                        $classification = $valid;
                        break;
                    }
                }
            }
        }
        
        $jobNumber = trim(is_array($row['job_number'] ?? '') ? json_encode($row['job_number'] ?? '') : ($row['job_number'] ?? ''));

        // Enhanced duplicate checking with additional fields for better accuracy
        // Use a more flexible date comparison to handle timezone/time differences
        $query = PettyCashDisbursement::where('receiver', $receiver)
            ->where('account', $account)
            ->where('amount', $amount)
            ->whereDate('created_at', $date->format('Y-m-d'));

        // Add additional fields to duplicate check if they exist
        if (!empty($description)) {
            $query->where('description', $description);
        }

        if (!empty($projectName)) {
            $query->where('project_name', $projectName);
        }

        if (!empty($classification)) {
            $query->where('classification', $classification);
        }

        if (!empty($jobNumber)) {
            $query->where('job_number', $jobNumber);
        }

        $exists = $query->exists();
        
        return $exists;
    }

    private function prepareDisbursementData($row, $date): array
    {
        $amount = 0;
        if (isset($row['amount']) && !empty(trim(is_array($row['amount']) ? json_encode($row['amount']) : $row['amount']))) {
            $amount = (float) str_replace([',', ' '], '', is_array($row['amount']) ? json_encode($row['amount']) : $row['amount']);
        }

        // Get a suitable top-up for this disbursement
        $topUpId = $this->getSuitableTopUp($amount);
        
        // Normalize classification
        $classification = '';
        if (!empty($row['classification'] ?? null)) {
            $classification = strtolower(trim(is_array($row['classification']) ? json_encode($row['classification']) : $row['classification']));
            // Apply the same normalization as in validateRowData
            $classificationMap = [
                'administrative' => 'admin',
                'administration' => 'admin',
                'agency' => 'agencies',
                'corporate' => 'agencies',
                'operation' => 'operations',
                'operational' => 'operations',
                'other expenses' => 'other',
                'miscellaneous' => 'other'
            ];
            
            if (isset($classificationMap[$classification])) {
                $classification = $classificationMap[$classification];
            } else {
                // Try to match partial strings
                $validClassifications = ['admin', 'agencies', 'operations', 'other'];
                foreach ($validClassifications as $valid) {
                    if (strpos($classification, $valid) !== false) {
                        $classification = $valid;
                        break;
                    }
                }
            }
        }
        
        // Normalize tax
        $tax = '';
        if (!empty($row['tax'] ?? null)) {
            $tax = strtolower(str_replace([' ', '_'], '', trim(is_array($row['tax']) ? json_encode($row['tax']) : $row['tax'])));
            // Apply the same normalization as in validateRowData
            $taxMap = [
                'noetr' => 'no_etr',
                'no etr' => 'no_etr',
                'etr' => 'etr',
                'yes' => 'etr',
                'no' => 'no_etr'
            ];
            
            if (isset($taxMap[$tax])) {
                $tax = $taxMap[$tax];
            }
        }

        return [
            'top_up_id' => $topUpId,
            'receiver' => trim(is_array($row['receiver'] ?? '') ? json_encode($row['receiver'] ?? '') : ($row['receiver'] ?? '')),
            'account' => trim(is_array($row['account'] ?? '') ? json_encode($row['account'] ?? '') : ($row['account'] ?? '')),
            'amount' => $amount,
            'description' => trim(is_array($row['description'] ?? '') ? json_encode($row['description'] ?? '') : ($row['description'] ?? '')),
            'project_name' => trim(is_array($row['project_name'] ?? '') ? json_encode($row['project_name'] ?? '') : ($row['project_name'] ?? '')),
            'classification' => $classification,
            'job_number' => trim(is_array($row['job_number'] ?? '') ? json_encode($row['job_number'] ?? '') : ($row['job_number'] ?? '')),
            'tax' => $tax,
            'payment_method' => $row['payment_method'] ?? 'cash',
            'transaction_code' => $row['transaction_code'] ?? ('IMP-' . time() . '-' . rand(1000, 9999)),
            'status' => 'active',
            'created_by' => auth()->id() ?? 1, // Default to system user if not authenticated
            'created_at' => $date ? $date->format('Y-m-d 00:00:00') : now()->format('Y-m-d 00:00:00'),
            'updated_at' => now(),
        ];
    }

    private function addSuccessfulRow($rowNumber, $disbursementId)
    {
        $this->results['successful'][] = [
            'row' => $rowNumber,
            'disbursement_id' => $disbursementId,
            'timestamp' => now()->toDateTimeString(),
            'field_values' => $this->getCurrentRowData()
        ];
    }

    private function addFailedRow($rowNumber, $errors)
    {
        $this->results['failed'][] = [
            'row' => $rowNumber,
            'errors' => $errors,
            'timestamp' => now()->toDateTimeString(),
            'field_values' => $this->getCurrentRowData()
        ];
    }

    private function addDuplicateRow($rowNumber, $reason)
    {
        $this->results['duplicates'][] = [
            'row' => $rowNumber,
            'reason' => $reason,
            'timestamp' => now()->toDateTimeString(),
            'field_values' => $this->getCurrentRowData()
        ];
    }

    /**
     * Get a suitable top-up for the disbursement amount.
     *
     * @param float $amount
     * @return int|null
     */
    private function getSuitableTopUp(float $amount): ?int
    {
        // Try to find a top-up with sufficient balance
        $topUp = \App\Modules\Finance\PettyCash\Models\PettyCashTopUp::where('remaining_balance', '>=', $amount)
            ->orderBy('created_at', 'desc')
            ->first();

        // If no top-up with sufficient balance, use the most recent one
        if (!$topUp) {
            $topUp = \App\Modules\Finance\PettyCash\Models\PettyCashTopUp::orderBy('created_at', 'desc')
                ->first();
        }

        return $topUp ? $topUp->id : null;
    }

    public function getResults(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'processed_rows' => $this->processedRows,
            'successful_imports' => count($this->results['successful']),
            'failed_rows' => $this->results['failed'],
            'duplicates' => $this->results['duplicates']
        ];
    }

    public function rules(): array
    {
        return [
            'date' => 'required',
            'receiver' => 'required|string|max:255',
            'account' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'description' => 'required|string|max:2000',
            'project_name' => 'nullable|string|max:255',
            'classification' => 'required|in:admin,agencies,operations,other',
            'job_number' => 'nullable|string|max:100',
            'tax' => 'required|string'
        ];
    }

    public function customValidationMessages()
    {
        return [
            'date.required' => 'DATE is required',
            'receiver.required' => 'RECEIVER is required',
            'receiver.max' => 'RECEIVER must not exceed 255 characters',
            'account.required' => 'ACCOUNT is required',
            'account.max' => 'ACCOUNT must not exceed 255 characters',
            'amount.required' => 'AMOUNT is required',
            'amount.numeric' => 'AMOUNT must be a number',
            'amount.min' => 'AMOUNT must be greater than or equal to 0',
            'description.required' => 'DESCRIPTION is required',
            'description.max' => 'DESCRIPTION must not exceed 2000 characters',
            'project_name.max' => 'PROJECT NAME must not exceed 255 characters',
            'classification.required' => 'CLASS is required',
            'classification.in' => 'CLASS must be one of: admin, agencies, operations, other',
            'job_number.max' => 'JOB NO. must not exceed 100 characters',
            'tax.required' => 'TAX is required'
        ];
    }

    public function chunkSize(): int
    {
        return 100; // Process 100 rows at a time for better performance
    }

    private function getCurrentRowData(): array
    {
        return $this->currentRowData;
    }
}