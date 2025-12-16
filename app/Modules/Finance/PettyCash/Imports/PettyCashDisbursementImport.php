<?php

namespace App\Modules\Finance\PettyCash\Imports;

use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class PettyCashDisbursementImport implements ToCollection, WithHeadingRow, WithValidation, WithChunkReading, ShouldQueue
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
        try {
            // Parse and validate DATE
            $date = $this->parseDate($row['date'] ?? null);
            if (!$date) {
                $this->addFailedRow($rowNumber, ['Invalid date format. Expected MM/DD/YYYY']);
                return;
            }

            // Validate required fields
            $validationErrors = $this->validateRowData($row);
            if (!empty($validationErrors)) {
                $this->addFailedRow($rowNumber, $validationErrors);
                return;
            }

            // Check for duplicates
            if ($this->isDuplicate($row, $date)) {
                $this->addDuplicateRow($rowNumber, 'Duplicate transaction found');
                return;
            }

            // Check balance availability
            $amount = (float) str_replace(',', '', $row['amount'] ?? 0);
            if (!$this->service->hasSufficientBalance($amount)) {
                $this->addFailedRow($rowNumber, ['Insufficient petty cash balance']);
                return;
            }

            // Create disbursement
            $disbursementData = $this->prepareDisbursementData($row, $date);
            $disbursement = $this->service->createDisbursement($disbursementData);
            
            if ($disbursement) {
                $this->addSuccessfulRow($rowNumber, $disbursement->id);
            } else {
                $this->addFailedRow($rowNumber, ['Failed to create disbursement']);
            }
            
        } catch (\Exception $e) {
            $this->addFailedRow($rowNumber, ['Unexpected error: ' . $e->getMessage()]);
        }
    }

    private function parseDate($dateString): ?\DateTime
    {
        if (empty($dateString)) {
            return null;
        }

        // Try to parse MM/DD/YYYY format
        $date = \DateTime::createFromFormat('m/d/Y', trim($dateString));
        if ($date) {
            return $date;
        }

        // Try alternative formats if needed
        $date = \DateTime::createFromFormat('m-d-Y', trim($dateString));
        if ($date) {
            return $date;
        }

        return null;
    }

    private function validateRowData($row): array
    {
        $errors = [];

        // Required fields
        $requiredFields = [
            'receiver' => 'Receiver',
            'account' => 'Account',
            'amount' => 'Amount',
            'description' => 'Description',
            'classification' => 'Classification',
            'tax' => 'Tax'
        ];

        foreach ($requiredFields as $field => $label) {
            if (empty($row[$field] ?? null)) {
                $errors[] = "{$label} is required";
            }
        }

        // Validate amount format
        if (!empty($row['amount'] ?? null)) {
            $amount = str_replace(',', '', $row['amount']);
            if (!is_numeric($amount) || (float)$amount <= 0) {
                $errors[] = 'Amount must be a positive number';
            }
        }

        // Validate classification
        $validClassifications = ['admin', 'agencies', 'operations', 'other'];
        if (!empty($row['classification'] ?? null) && !in_array(strtolower($row['classification']), $validClassifications)) {
            $errors[] = 'Invalid classification. Must be one of: admin, agencies, operations, other';
        }

        // Validate tax
        $validTaxValues = ['etr', 'no_etr'];
        if (!empty($row['tax'] ?? null) && !in_array(strtolower($row['tax']), $validTaxValues)) {
            $errors[] = 'Invalid tax value. Must be one of: etr, no_etr';
        }

        return $errors;
    }

    private function isDuplicate($row, $date): bool
    {
        $receiver = trim($row['receiver'] ?? '');
        $account = trim($row['account'] ?? '');
        $amount = (float) str_replace(',', '', $row['amount'] ?? 0);
        $description = trim($row['description'] ?? '');
        $projectName = trim($row['project_name'] ?? '');

        // Enhanced duplicate checking with additional fields for better accuracy
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

        return $query->exists();
    }

    private function prepareDisbursementData($row, $date): array
    {
        $amount = (float) str_replace(',', '', $row['amount'] ?? 0);

        return [
            'receiver' => trim($row['receiver'] ?? ''),
            'account' => trim($row['account'] ?? ''),
            'amount' => $amount,
            'description' => trim($row['description'] ?? ''),
            'project_name' => trim($row['project_name'] ?? ''),
            'classification' => strtolower(trim($row['classification'] ?? '')),
            'job_number' => trim($row['job_number'] ?? ''),
            'tax' => strtolower(trim($row['tax'] ?? '')),
            'payment_method' => 'cash', // Default payment method for imports
            'transaction_code' => 'IMP-' . time() . '-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => auth()->id() ?? 1, // Default to system user if not authenticated
            'created_at' => $date,
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
            'date' => 'required|date_format:m/d/Y',
            'receiver' => 'required|string',
            'account' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
            'classification' => 'required|in:admin,agencies,operations,other',
            'tax' => 'required|in:etr,no_etr'
        ];
    }

    public function customValidationMessages()
    {
        return [
            'date.required' => 'DATE is required',
            'date.date_format' => 'DATE must be in MM/DD/YYYY format',
            'receiver.required' => 'Receiver is required',
            'account.required' => 'ACCOUNT is required',
            'amount.required' => 'AMOUNT is required',
            'amount.numeric' => 'AMOUNT must be a number',
            'amount.min' => 'AMOUNT must be greater than 0',
            'description.required' => 'DESCRIPTION is required',
            'classification.required' => 'CLASS is required',
            'classification.in' => 'CLASS must be one of: admin, agencies, operations, other',
            'tax.required' => 'TAX is required',
            'tax.in' => 'TAX must be one of: etr, no_etr'
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