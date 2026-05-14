<?php

namespace App\Modules\Projects\Services;

use App\Models\ProjectEnquiry;
use App\Models\Project;
use App\Constants\EnquiryConstants;
use Illuminate\Support\Facades\DB;

class SequencingService
{
    /**
     * Generate the next unique number for a given prefix and column.
     *
     * @param string $prefixCode e.g., 'ENQ', 'WNG'
     * @param string $column e.g., 'enquiry_number', 'job_number'
     * @param string $modelClass The model to query
     * @return string
     */
    public function generateNextNumber(string $prefixCode, string $column, string $modelClass): string
    {
        $now = now();
        $year = $now->year;
        $month = str_pad($now->month, 2, '0', STR_PAD_LEFT);
        
        // Format: PRX-MM-YYYY-
        $prefix = "{$prefixCode}-{$month}-{$year}-";

        // Find the highest existing number for this month/year prefix
        $lastRecord = $modelClass::where($column, 'like', $prefix . '%')
            ->orderByRaw("CAST(SUBSTRING({$column}, LENGTH(?) + 1) AS UNSIGNED) DESC", [$prefix])
            ->first();

        $nextNumber = 1;
        if ($lastRecord) {
            $numberPart = substr($lastRecord->$column, strlen($prefix));
            $nextNumber = intval($numberPart) + 1;
        }

        return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate Enquiry Number
     */
    public function generateEnquiryNumber(): string
    {
        return $this->generateNextNumber(EnquiryConstants::ENQUIRY_PREFIX, 'enquiry_number', ProjectEnquiry::class);
    }

    /**
     * Generate Job Number based on enquiry type
     */
    public function generateJobNumber(?string $presetType = null): string
    {
        $prefixCode = $this->getPrefixForPreset($presetType);
        return $this->generateNextNumber($prefixCode, 'job_number', ProjectEnquiry::class);
    }

    /**
     * Generate Project ID
     */
    public function generateProjectId(?string $presetType = null): string
    {
        $prefixCode = $this->getPrefixForPreset($presetType);
        return $this->generateNextNumber($prefixCode, 'project_id', Project::class);
    }

    /**
     * Helper to determine prefix based on preset type
     */
    private function getPrefixForPreset(?string $presetType = null): string
    {
        return match($presetType) {
            'internal_job' => EnquiryConstants::INTERNAL_PREFIX,
            'sponsorship' => EnquiryConstants::SPONSORSHIP_PREFIX,
            default => EnquiryConstants::PROJECT_PREFIX,
        };
    }
}
