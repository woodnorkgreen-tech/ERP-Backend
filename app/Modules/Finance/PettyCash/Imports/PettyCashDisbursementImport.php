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
        // Ensure row is an array
        if ($row instanceof Collection) {
            $row = $row->all();
        } elseif (is_object($row)) {
            $row = (array) $row;
        }
        
        // Normalize column names to lowercase for case-insensitive matching
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            // Remove non-alphanumeric characters and lowercase
            $normalizedKey = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$key));
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
            'project' => 'project_name',
            'tax' => 'tax',
            'taxation' => 'tax',
            'class' => 'classification',
            'classification' => 'classification',
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
        
        // Fallback for numeric keys (if header row detection failed or column missing)
        $fallbacks = [
            0 => 'date',
            1 => 'receiver',
            2 => 'account',
            3 => 'amount',
            4 => 'description',
            5 => 'classification',
            6 => 'tax',
            7 => 'project_name',
            8 => 'job_number'
        ];
        
        foreach ($fallbacks as $index => $targetKey) {
            if (!isset($mappedRow[$targetKey]) && isset($row[$index])) {
                $mappedRow[$targetKey] = $row[$index];
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

            // Parse and validate DATE (Default to now if failing, to allow "bypass required")
            $date = $this->parseDate($mappedRow['date'] ?? null);
            if (!$date) {
                // Instead of failing, default to today so the import continues
                $date = new \DateTime(); 
            }

            // Check for duplicates (using date_disbursed instead of created_at)
            if ($date && $this->isDuplicate($mappedRow, $date)) {
                $this->addDuplicateRow($rowNumber, 'Duplicate transaction found');
                return;
            }

            // Balance check is now handled inside createDisbursement service
            // with a bypass flag for imports if needed.
            // We'll remove the manual check here to avoid double-dipping and allow negative balance imports.
            
            // Calculate amount for the check below
            $amount = 0;
            if (isset($mappedRow['amount']) && !empty(trim(is_array($mappedRow['amount']) ? json_encode($mappedRow['amount']) : (string)$mappedRow['amount']))) {
                $amount = (float) preg_replace('/[^0-9.-]/', '', (string)$mappedRow['amount']);
            }

            // Create disbursement (only if we have meaningful data)
            if ($amount > 0 || !empty($mappedRow['receiver'] ?? null)) {
                $disbursementData = $this->prepareDisbursementData($mappedRow, $date);
                if (!$disbursementData['top_up_id']) {
                    $this->addFailedRow($rowNumber, ['No top-up found with sufficient balance. Please ensure you have topped up the petty cash account.']);
                    return;
                }
                
                $result = $this->service->createDisbursement($disbursementData);
                
                if (isset($result['success']) && $result['success']) {
                    $disbursement = $result['data'];
                    $this->addSuccessfulRow($rowNumber, $disbursement->id);
                } else {
                    $errors = isset($result['errors']) ? (is_array($result['errors']) ? array_values($result['errors'])[0] : $result['errors']) : ['Failed to create disbursement'];
                    $this->addFailedRow($rowNumber, (array)$errors);
                }
            }
            
        } catch (\Exception $e) {
            $this->addFailedRow($rowNumber, ['Unexpected error: ' . $e->getMessage()]);
        }
    }

    private function normalizeMappedRow(array $row): array
    {
        // Trim and cast basic fields
        if (isset($row['receiver'])) $row['receiver'] = (string)$row['receiver'];
        if (isset($row['account'])) $row['account'] = (string)$row['account'];
        if (isset($row['description'])) $row['description'] = (string)$row['description'];

        // Normalize amount (remove commas, spaces, and currency symbols)
        if (isset($row['amount']) && !empty($row['amount'])) {
            $amt = is_array($row['amount']) ? json_encode($row['amount']) : (string)$row['amount'];
            // Remove everything except digits, dots, and hyphens
            $row['amount'] = preg_replace('/[^0-9.-]/', '', str_replace(',', '', $amt));
        }

        // Normalize classification
        if (!empty($row['classification'] ?? null)) {
            $val = strtolower($row['classification']);
            $map = [
                'admin' => 'admin',
                'administrative' => 'admin',
                'administration' => 'admin',
                'office' => 'admin',
                'stationery' => 'admin',
                'agencies' => 'agencies',
                'agency' => 'agencies',
                'event planners' => 'event_planners',
                'event_planners' => 'event_planners',
                'event' => 'event_planners',
                'events' => 'event_planners',
                'corporates' => 'corporates',
                'corporate' => 'corporates',
                'crs' => 'crs',
                'operations' => 'operations',
                'operation' => 'operations',
                'operational' => 'operations',
                'transport' => 'operations',
                'fuel' => 'operations',
                'site' => 'operations',
                'other' => 'other',
                'other expenses' => 'other',
                'miscellaneous' => 'other',
                'misc' => 'other'
            ];
            
            if (isset($map[$val])) {
                $row['classification'] = $map[$val];
            } else {
                // Try partial match
                foreach (['admin', 'agencies', 'operations', 'event_planners', 'corporates', 'crs', 'other'] as $valid) {
                    if (strpos($val, str_replace('_', ' ', $valid)) !== false || strpos($val, $valid) !== false) {
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
            'Y-m-d',    // YYYY-MM-DD
            'Y/m/d',    // YYYY/MM/DD
            'd-m-Y',    // DD-MM-YYYY
            'm-d-Y',    // MM-DD-YYYY
            'M j, Y',   // Jan 1, 2025
            'F j, Y',   // January 1, 2025
            'j/m/Y',    // D/M/YYYY
            'j-m-Y',    // D-M-YYYY
            'j/m/y',    // D/M/YY
            'j-m-y',    // D-M-YY
            'd.m.Y',    // DD.MM.YYYY
            'm.d.Y',    // MM.DD.YYYY
            'Y.m.d',    // YYYY.MM.DD
            'd/m/y',    // DD/MM/YY (Excel often uses this)
            'm/d/y',    // MM/DD/YY
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
                $validClassifications = ['admin', 'agencies', 'operations', 'event_planners', 'corporates', 'crs', 'other'];
                foreach ($validClassifications as $valid) {
                    if (strpos($classification, str_replace('_', ' ', $valid)) !== false || strpos($classification, $valid) !== false) {
                        $classification = $valid;
                        break;
                    }
                }
            }
        }
        
        $jobNumber = trim(is_array($row['job_number'] ?? '') ? json_encode($row['job_number'] ?? '') : ($row['job_number'] ?? ''));

        // Enhanced duplicate checking with additional fields for better accuracy
        $query = PettyCashDisbursement::where('receiver', $receiver)
            ->where('account', $account)
            ->where('amount', $amount)
            ->whereDate('date_disbursed', $date->format('Y-m-d'));

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
                $validClassifications = ['admin', 'agencies', 'operations', 'event_planners', 'corporates', 'crs', 'other'];
                foreach ($validClassifications as $valid) {
                    if (strpos($classification, str_replace('_', ' ', $valid)) !== false || strpos($classification, $valid) !== false) {
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

        // Create defaults for missing values to bypass strict DB/Validation
        $receiver = trim(is_array($row['receiver'] ?? '') ? json_encode($row['receiver'] ?? '') : ($row['receiver'] ?? ''));
        if (empty($receiver)) $receiver = 'Unknown Payee';

        $account = trim(is_array($row['account'] ?? '') ? json_encode($row['account'] ?? '') : ($row['account'] ?? ''));
        if (empty($account)) $account = 'General Ledger';

        $description = trim(is_array($row['description'] ?? '') ? json_encode($row['description'] ?? '') : ($row['description'] ?? ''));
        if (empty($description)) $description = 'Imported transaction (' . now()->format('Y-m-d') . ')';

        if (empty($classification)) $classification = 'other';
        if (empty($tax)) $tax = 'no_etr';

        return [
            'top_up_id' => $topUpId,
            'receiver' => $receiver,
            'account' => $account,
            'amount' => $amount > 0 ? $amount : 0.00,
            'description' => $description,
            'project_name' => trim(is_array($row['project_name'] ?? '') ? json_encode($row['project_name'] ?? '') : ($row['project_name'] ?? '')),
            'classification' => $classification,
            'job_number' => trim(is_array($row['job_number'] ?? '') ? json_encode($row['job_number'] ?? '') : ($row['job_number'] ?? '')),
            'tax' => $tax,
            'payment_method' => $row['payment_method'] ?? 'cash',
            'transaction_code' => $row['transaction_code'] ?? ('IMP-' . time() . '-' . rand(1000, 9999)),
            'status' => 'active',
            'created_by' => auth()->id() ?? 1, // Default to system user if not authenticated
            'date_disbursed' => $date ? $date->format('Y-m-d') : now()->format('Y-m-d'),
            'skip_balance_check' => true, // Bypass balance check for imports
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
        // Try to find a top-up with sufficient raw funds available
        // Note: We can't use the virtual 'remaining_balance' in a where clause
        $topUp = \App\Modules\Finance\PettyCash\Models\PettyCashTopUp::withSum('activeDisbursements', 'amount')
            ->get()
            ->filter(function($tu) use ($amount) {
                $remaining = $tu->amount - ($tu->active_disbursements_sum_amount ?? 0);
                return $remaining >= $amount;
            })
            ->sortByDesc('created_at')
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
        // Relaxing rules to the absolute minimum to allow "messy" data imports
        return [
            'date' => 'nullable',
            'receiver' => 'nullable',
            'account' => 'nullable',
            'amount' => 'nullable',
            'description' => 'nullable',
            'project_name' => 'nullable',
            'classification' => 'nullable',
            'job_number' => 'nullable',
            'tax' => 'nullable'
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
            'classification.in' => 'CLASS must be one of: admin, agencies, operations, event_planners, corporates, crs, other',
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