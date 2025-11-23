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
     * Create a new archival report
     */
    public function createReport(int $taskId, array $data): ArchivalReport
    {
        return DB::transaction(function () use ($taskId, $data) {
            // Extract related data
            $setupItems = $data['setup_items'] ?? [];
            $itemPlacements = $data['item_placements'] ?? [];
            unset($data['setup_items'], $data['item_placements']);

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
            'url' => asset('storage/' . $path),
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
            'projectEnquiry',
            'productionData',
            'siteSurvey',
            'logisticsTask'
        ])->findOrFail($taskId);

        $data = [];

        // From Project Enquiry
        if ($task->projectEnquiry) {
            $data['client_name'] = $task->projectEnquiry->client_name;
            $data['project_code'] = $task->projectEnquiry->project_number;
            $data['site_location'] = $task->projectEnquiry->event_location;
        }

        // From Production Data
        if ($task->productionData) {
            $data['production_start_date'] = $task->productionData->production_start_date;
            
            // Extract materials from production elements
            $materials = [];
            foreach ($task->productionData->production_elements ?? [] as $element) {
                if (!empty($element['material'])) {
                    $materials[] = $element['material'];
                }
            }
            if (!empty($materials)) {
                $data['materials_used_in_production'] = implode(', ', array_unique($materials));
            }
        }

        // From Logistics Task
        if ($task->logisticsTask) {
            // Team information
            if ($task->logisticsTask->team_members) {
                $teamMembers = json_decode($task->logisticsTask->team_members, true);
                if (!empty($teamMembers)) {
                    $data['setup_team_assigned'] = implode(', ', array_column($teamMembers, 'name'));
                }
            }

            // Transport items → Setup items
            $setupItems = [];
            foreach ($task->logisticsTask->transportItems ?? [] as $item) {
                $setupItems[] = [
                    'deliverable_item' => $item->name ?? $item->description,
                    'status' => 'pending',
                ];
            }
            if (!empty($setupItems)) {
                $data['setup_items'] = $setupItems;
            }
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
