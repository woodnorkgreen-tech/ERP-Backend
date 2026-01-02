<?php

namespace App\Modules\ArchivalTask\Services;

use App\Modules\ArchivalTask\Models\ArchivalReport;
use App\Modules\ArchivalTask\Models\ArchivalSetupItem;
use App\Modules\ArchivalTask\Models\ArchivalItemPlacement;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArchivalReportService
{
    /**
     * Get archival report for a task
     */
    public function getReportByTask(int $taskId): ?ArchivalReport
    {
        return ArchivalReport::with(['setupItems', 'itemPlacements', 'creator'])
            ->where('enquiry_task_id', $taskId)
            ->first();
    }

    /**
     * Get archival report by ID
     */
    public function getReportById(int $reportId): ?ArchivalReport
    {
        return ArchivalReport::with(['setupItems', 'itemPlacements', 'creator'])
            ->find($reportId);
    }

    /**
     * Create a new archival report
     */
    public function createReport(int $taskId, array $data): ArchivalReport
    {
        return DB::transaction(function () use ($taskId, $data) {
            // Extract related data
            $setupItems = $data['setup_items'] ?? [];
            $itemPlacements = $data['item_placements'] ?? [];
            unset($data['setup_items'], $data['item_placements']);

            // Transform project_scope array to JSON string if it's an array
            if (isset($data['project_scope']) && is_array($data['project_scope'])) {
                $data['project_scope'] = json_encode($data['project_scope']);
            }

            // Create main report
            $report = ArchivalReport::create([
                'enquiry_task_id' => $taskId,
                'created_by' => auth()->id(),
                ...$data,
            ]);

            // Create related items
            if (!empty($setupItems)) {
                foreach ($setupItems as $item) {
                    $report->setupItems()->create($item);
                }
            }

            if (!empty($itemPlacements)) {
                foreach ($itemPlacements as $placement) {
                    $report->itemPlacements()->create($placement);
                }
            }

            return $report->load(['setupItems', 'itemPlacements']);
        });
    }

    /**
     * Update an archival report
     */
    public function updateReport(int $reportId, array $data): ArchivalReport
    {
        return DB::transaction(function () use ($reportId, $data) {
            $report = ArchivalReport::findOrFail($reportId);

            // Extract related data
            $setupItems = $data['setup_items'] ?? null;
            $itemPlacements = $data['item_placements'] ?? null;
            unset($data['setup_items'], $data['item_placements']);

            // Transform project_scope array to JSON string if it's an array
            if (isset($data['project_scope']) && is_array($data['project_scope'])) {
                $data['project_scope'] = json_encode($data['project_scope']);
            }

            // Update main report
            $report->update($data);

            // Update setup items if provided
            if ($setupItems !== null) {
                // Delete existing items
                $report->setupItems()->delete();
                
                // Create new items
                foreach ($setupItems as $item) {
                    if (!empty($item['deliverable_item'])) {
                        $report->setupItems()->create($item);
                    }
                }
            }

            // Update item placements if provided
            if ($itemPlacements !== null) {
                // Delete existing placements
                $report->itemPlacements()->delete();
                
                // Create new placements
                foreach ($itemPlacements as $placement) {
                    if (!empty($placement['section_area'])) {
                        $report->itemPlacements()->create($placement);
                    }
                }
            }

            return $report->fresh(['setupItems', 'itemPlacements']);
        });
    }

    /**
     * Delete an archival report
     */
    public function deleteReport(int $reportId): bool
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        // Delete associated files
        if ($report->attachments) {
            foreach ($report->attachments as $attachment) {
                if (isset($attachment['path'])) {
                    Storage::disk('public')->delete($attachment['path']);
                }
            }
        }

        return $report->delete();
    }

    /**
     * Upload attachment to report
     */
    public function uploadAttachment(int $reportId, $file, string $category): array
    {
        $report = ArchivalReport::findOrFail($reportId);

        // Store file
        $path = $file->store("archival-reports/{$report->enquiry_task_id}", 'public');
        
        $attachmentData = [
            'id' => uniqid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $category,
            'uploaded_at' => now()->toISOString(),
        ];

        // Add to attachments array
        $attachments = $report->attachments ?? [];
        $attachments[] = $attachmentData;
        
        $report->update(['attachments' => $attachments]);

        return [
            ...$attachmentData,
                'url' => storage_url($path),
        ];
    }

    /**
     * Delete attachment from report
     */
    public function deleteAttachment(int $reportId, string $attachmentId): bool
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        $attachments = $report->attachments ?? [];
        $index = array_search($attachmentId, array_column($attachments, 'id'));
        
        if ($index === false) {
            return false;
        }

        // Delete file from storage
        if (isset($attachments[$index]['path'])) {
            Storage::disk('public')->delete($attachments[$index]['path']);
        }

        // Remove from array
        array_splice($attachments, $index, 1);
        $report->update(['attachments' => array_values($attachments)]);

        return true;
    }

    /**
     * Auto-populate report data from other tasks
     */
    public function autoPopulateData(int $taskId): array
    {
        $task = EnquiryTask::with([
            'enquiry.projectOfficer',
            'enquiry.client'
        ])->findOrFail($taskId);

        $data = [];

        // From Project Enquiry
        if ($task->enquiry) {
            // Client name from relationship (use company_name or full_name)
            if ($task->enquiry->client) {
                $data['client_name'] = $task->enquiry->client->company_name ?? $task->enquiry->client->full_name;
            }
            
            // Project code from job_number or enquiry_number
            $data['project_code'] = $task->enquiry->job_number ?? $task->enquiry->enquiry_number;
            
            // Site location from venue field
            $data['site_location'] = $task->enquiry->venue;
            
            // Project officer
            if ($task->enquiry->projectOfficer) {
                $data['project_officer'] = $task->enquiry->projectOfficer->name;
            }
            
            // Format dates to Y-m-d for HTML date inputs
            if ($task->enquiry->start_date) {
                $data['start_date'] = $task->enquiry->start_date->format('Y-m-d');
            }
            
            $endDate = $task->enquiry->end_date ?? $task->enquiry->expected_delivery_date;
            if ($endDate) {
                $data['end_date'] = $endDate->format('Y-m-d');
            }
            
            $data['project_scope'] = $task->enquiry->project_scope ?? $task->enquiry->description;
        }

        return $data;
    }

    /**
     * Change report status
     */
    public function changeStatus(int $reportId, string $status): ArchivalReport
    {
        $report = ArchivalReport::findOrFail($reportId);
        $report->update(['status' => $status]);
        
        return $report;
    }

    /**
     * Get reports by status
     */
    public function getReportsByStatus(string $status): array
    {
        return ArchivalReport::byStatus($status)
            ->with(['enquiryTask', 'creator'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }
}
