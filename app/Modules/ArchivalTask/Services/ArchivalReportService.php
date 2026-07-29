<?php

namespace App\Modules\ArchivalTask\Services;

use App\Modules\ArchivalTask\Models\ArchivalReport;
use App\Modules\ArchivalTask\Models\ArchivalSetupItem;
use App\Modules\ArchivalTask\Models\ArchivalItemPlacement;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\SiteSurvey;
use App\Models\HandoverSurvey;
use App\Models\DesignAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ArchivalReportService
{
    public function getReportByTask(int $taskId): ?array
    {
        $report = ArchivalReport::with(['setupItems', 'itemPlacements', 'creator'])
            ->where('enquiry_task_id', $taskId)
            ->first();

        if (!$report) {
            return null;
        }

        $reportData = $report->toArray();
        $reportData['attachment_urls'] = $report->attachment_urls;
        $reportData['system_documents'] = $this->getSystemDocuments($taskId);
        $reportData['financial_summary'] = $this->getFinancialSummary($taskId);
        $reportData['capabilities'] = [
            'can_edit' => $report->status === 'draft',
            'can_approve' => auth()->user()?->hasRole(['Super Admin', 'Admin', 'Project Manager']) ?? false,
        ];

        return $reportData;
    }

    /**
     * Read-only reconciliation from the existing quote and payment records.
     * An unpaid balance stays owned by Finance and is disclosed at closure; it
     * does not duplicate or replace the receivables workflow.
     */
    public function getFinancialSummary(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);
        $enquiry = $task->enquiry;

        if (!$enquiry) {
            return [];
        }

        $quoteTaskIds = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->whereIn('type', ['quote', 'quote_approval'])
            ->pluck('id');
        $quote = \App\Models\TaskQuoteData::whereIn('enquiry_task_id', $quoteTaskIds)
            ->orderByDesc('approval_date')
            ->orderByDesc('id')
            ->first();

        $quotedAmount = (float) ($enquiry->client_approved_quote
            ?? $quote?->quote_amount
            ?? $quote?->excel_quote_amount
            ?? data_get($quote?->totals, 'grand_total', 0));
        $paidAmount = (float) \App\Models\EnquiryPayment::where('project_enquiry_id', $enquiry->id)->sum('amount');
        $balance = max($quotedAmount - $paidAmount, 0);

        return [
            'currency' => 'KES',
            'approved_quote' => round($quotedAmount, 2),
            'payments_received' => round($paidAmount, 2),
            'outstanding_balance' => round($balance, 2),
            'payment_status' => $quotedAmount <= 0 ? 'not_available' : ($balance <= 0 ? 'settled' : ($paidAmount > 0 ? 'part_paid' : 'unpaid')),
            'finance_released' => (bool) $enquiry->finance_released,
            'note' => $balance > 0 ? 'Outstanding collection remains active in Finance after operational closure.' : null,
        ];
    }

    /**
     * Get system generated documents for the project
     */
    public function getSystemDocuments(int $taskId): array
    {
        try {
            $task = EnquiryTask::findOrFail($taskId);
            $enquiryId = $task->project_enquiry_id;

            if (!$enquiryId) {
                return [];
            }

            $documents = [];
            
            // Optimization: Fetch all valid tasks for this enquiry key types eager loaded
            $allTasks = EnquiryTask::where('project_enquiry_id', $enquiryId)
                ->whereIn('type', ['materials', 'budget', 'logistics', 'design'])
                ->get()
                ->keyBy('type');

            // 1. Fetch Material List PDF
            try {
                if (isset($allTasks['materials'])) {
                    $materialsTask = $allTasks['materials'];
                    if (\App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Material List', 'type' => 'PDF', 'category' => 'Materials',
                            'url' => "/api/projects/tasks/{$materialsTask->id}/materials/pdf",
                            'task_id' => $materialsTask->id, 'task_status' => $materialsTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 2. Fetch Budget PDF
            try {
               if (isset($allTasks['budget'])) {
                    $budgetTask = $allTasks['budget'];
                    if (\App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Project Budget', 'type' => 'PDF', 'category' => 'Financials',
                            'url' => "/api/projects/tasks/{$budgetTask->id}/budget/pdf",
                            'task_id' => $budgetTask->id, 'task_status' => $budgetTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 3. Fetch Logistics Report PDF
            try {
                if (isset($allTasks['logistics'])) {
                    $logisticsTask = $allTasks['logistics'];
                    if (\App\Modules\logisticsTask\Models\LogisticsTask::where('task_id', $logisticsTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Logistics Manifest', 'type' => 'PDF', 'category' => 'Logistics',
                            'url' => "/api/projects/tasks/{$logisticsTask->id}/logistics/pdf",
                            'task_id' => $logisticsTask->id, 'task_status' => $logisticsTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 4. Fetch Design Assets
            try {
                if (isset($allTasks['design'])) {
                    $designTask = $allTasks['design'];
                    $assets = DesignAsset::where('enquiry_task_id', $designTask->id)->get();
                    foreach ($assets as $asset) {
                        $documents[] = [
                            'name' => "Design: {$asset->name}",
                            'type' => strtoupper(pathinfo($asset->file_path, PATHINFO_EXTENSION)),
                            'category' => 'Design',
                            'url' => storage_url($asset->file_path),
                            'asset_id' => $asset->id,
                            'task_status' => $designTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 5. Fetch Site Survey PDF
            try {
                $survey = SiteSurvey::where('project_enquiry_id', $enquiryId)->first();
                if ($survey) {
                    $documents[] = [
                        'name' => 'Site Survey Report',
                        'type' => 'PDF',
                        'category' => 'Field Work',
                        'url' => "/api/projects/site-surveys/{$survey->id}/pdf",
                        'survey_id' => $survey->id,
                        'task_status' => $survey->status,
                    ];
                }
            } catch (\Exception $e) {}

            // 6. Fetch Handover Survey
            try {
                $handover = HandoverSurvey::whereHas('task', function($q) use ($enquiryId) {
                    $q->where('project_enquiry_id', $enquiryId);
                })->first();
                if ($handover) {
                     $documents[] = [
                        'name' => 'Handover Certificate',
                        'type' => 'PDF',
                        'category' => 'Handover',
                        'url' => "/api/projects/tasks/{$handover->task_id}/handover/survey",
                        'handover_id' => $handover->id,
                        'task_status' => $handover->submitted ? 'completed' : 'pending',
                    ];
                }
            } catch (\Exception $e) {}

            return $documents;
        } catch (\Exception $e) {
            Log::error('Failed to get system documents for archival report', [
                'task_id' => $taskId ?? null,
                'error' => $e->getMessage()
            ]);
            return [];
        }
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

            // Update setup items if provided (upsert — keep existing IDs)
            if ($setupItems !== null) {
                $incomingIds = collect($setupItems)->pluck('id')->filter()->values()->all();
                $report->setupItems()->whereNotIn('id', $incomingIds)->delete();

                foreach ($setupItems as $item) {
                    if (empty($item['deliverable_item'])) continue;
                    $id = $item['id'] ?? null;
                    unset($item['id']);
                    if ($id) {
                        $report->setupItems()->where('id', $id)->update($item);
                    } else {
                        $report->setupItems()->create($item);
                    }
                }
            }

            // Update item placements if provided (upsert — keep existing IDs)
            if ($itemPlacements !== null) {
                $incomingIds = collect($itemPlacements)->pluck('id')->filter()->values()->all();
                $report->itemPlacements()->whereNotIn('id', $incomingIds)->delete();

                foreach ($itemPlacements as $placement) {
                    if (empty($placement['section_area'])) continue;
                    $id = $placement['id'] ?? null;
                    unset($placement['id']);
                    if ($id) {
                        $report->itemPlacements()->where('id', $id)->update($placement);
                    } else {
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
            
            $projectScope = $task->enquiry->project_scope ?? $task->enquiry->description;
            if (is_array($projectScope)) {
                $projectScope = collect($projectScope)
                    ->map(fn ($item) => is_array($item) ? ($item['name'] ?? null) : $item)
                    ->filter()
                    ->implode("\n");
            }
            $data['project_scope'] = $projectScope;
            
            // --- From Materials Task ---
            try {
                $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                    ->where('type', 'materials')
                    ->first();
                    
                if ($materialsTask) {
                    $materialsData = \App\Models\TaskMaterialsData::with('elements.materials')
                        ->where('enquiry_task_id', $materialsTask->id)
                        ->first();
                        
                    if ($materialsData && $materialsData->elements) {
                        $materialsList = [];
                        foreach ($materialsData->elements as $element) {
                            if (!$element->is_included) continue;
                            
                            foreach ($element->materials as $material) {
                                if ($material->is_included) {
                                    $materialsList[] = "- {$material->description}: {$material->quantity} {$material->unit}";
                                }
                            }
                        }
                        
                        if (!empty($materialsList)) {
                            $data['materials_used_in_production'] = implode("\n", $materialsList);
                        }

                        // --- Setup & Placements ---
                        $data['setup_items'] = [];
                        $data['item_placements'] = [];
                        
                        foreach ($materialsData->elements as $element) {
                            if (!$element->is_included) continue;
                            
                            // 1. Setup Item Allocation
                            $data['setup_items'][] = [
                                'deliverable_item' => $element->name,
                                'assigned_technician' => null,
                                'site_section' => $element->dimensions ? implode(' x ', $element->dimensions) : null,
                                'status' => 'pending',
                                'notes' => $element->notes
                            ];
                            
                            // 2. Item Placement Details
                            $data['item_placements'][] = [
                                'section_area' => $element->dimensions ? implode(' x ', $element->dimensions) : null,
                                'items_installed' => $element->name,
                                'placement_accuracy' => 'correct',
                                'observation' => $element->notes
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Archival Auto-populate: Materials fetch failed', ['error' => $e->getMessage()]);
            }
        }

        return $data;
    }

    /**
     * Change report status
     */
    public function changeStatus(int $reportId, string $status, $actor): ArchivalReport
    {
        $report = ArchivalReport::findOrFail($reportId);
        $changes = ['status' => $status];

        if ($status === 'submitted') {
            $changes += [
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'project_officer_signature' => $actor->name,
                'project_officer_sign_date' => now()->toDateString(),
            ];
        }

        if ($status === 'approved') {
            $changes += [
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reviewed_by' => $actor->name,
                'reviewer_sign_date' => now()->toDateString(),
            ];
        }

        $report->update($changes);
        
        return $report->fresh();
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

    /**
     * Analyze report and generate insights
     */
    public function analyzeReport(int $reportId): array
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        $analysis = [
            'action_plan' => [],
            'root_causes' => [],
            'best_practices' => [],
            'lessons_learnt' => [],
            'recommendations' => [],
            'summary_score' => 0, // 0-100
        ];

        // --- 1. Root Cause Analysis ---
        if ($report->delays_occurred) {
            $analysis['root_causes'][] = "Project Delays: " . ($report->delay_reasons ?? 'Unspecified reasons');
            $analysis['lessons_learnt'][] = "Investigate and mitigate sources of delay: " . ($report->delay_reasons ?? 'N/A');
        }

        if ($report->procurement_challenges) {
            $analysis['root_causes'][] = "Procurement Issues: " . $report->procurement_challenges;
            $analysis['action_plan'][] = "Review supplier list and procurement lead times.";
        }

        if ($report->delivery_issues) {
            $analysis['root_causes'][] = "Delivery Complications: " . ($report->delivery_notes ?? 'Check delivery logs');
        }

        if ($report->client_satisfaction === 'unsatisfied') {
            $analysis['root_causes'][] = "Client Dissatisfaction: " . ($report->client_remarks ?? 'Review client feedback meetings');
        }

        // --- 2. Action Plan Generation ---
        // Checklist Compliance
        $checklistItems = [
            'checklist_site_survey_form' => 'Conduct mandatory site surveys before mobilization.',
            'checklist_qc_checklist' => 'Enforce Quality Control (QC) checklist completion prior to dispatch.',
            'checklist_client_feedback' => 'Ensure formal client feedback form is signed off.',
            'checklist_setup_setdown' => 'Verify setup/setdown protocols are followed.'
        ];

        foreach ($checklistItems as $field => $action) {
            if (!$report->$field) {
                $analysis['action_plan'][] = $action;
            }
        }

        // Performance based actions
        if ($report->cleanliness_rating === 'poor' || $report->cleanliness_rating === 'fair') {
            $analysis['action_plan'][] = "Retrain team on site cleanliness and waste disposal standards.";
        }

        if ($report->print_clarity_rating === 'poor' || $report->printworks_accuracy_rating === 'poor') {
            $analysis['action_plan'][] = "Audit print vendors or internal print quality assurance processes.";
        }

        // --- 3. Best Practices & Successes ---
        if ($report->site_organization === 'excellent') {
            $analysis['best_practices'][] = "Site Organization: Maintained high standards of site layout and safety.";
        }

        if ($report->team_coordination === 'good') {
            $analysis['best_practices'][] = "Team Coordination: Effective communication and role allocation observed.";
        }

        if ($report->delivered_on_schedule && !$report->delays_occurred) {
            $analysis['best_practices'][] = "Time Management: Project executed and delivered strictly on schedule.";
        }

        if ($report->client_rating === 'excellent' || $report->client_satisfaction === 'satisfied') {
            $analysis['best_practices'][] = "Client Relations: Strong client confidence and satisfaction achieved.";
        }

        // --- 4. Lessons Learnt ---
        if ($report->setup_aligned_to_schedule === false) {
            $analysis['lessons_learnt'][] = "Setup Schedule Mismatch: Future schedules should factor in buffer time for site realities.";
        }

        if ($report->items_sourced_externally && $report->procurement_challenges) {
            $analysis['lessons_learnt'][] = "External Sourcing Risk: Develop backup options for critical external items.";
        }

        // --- 5. Recommendations (General) ---
        if (!empty($report->recommendations_action_points)) {
            $analysis['recommendations'][] = $report->recommendations_action_points;
        }

        if (count($analysis['root_causes']) > 0) {
            $analysis['recommendations'][] = "Conduct a post-mortem meeting to address identified root causes.";
        }

        // --- 6. Scoring (Simple Heuristic) ---
        $score = 100;
        if ($report->delays_occurred) $score -= 10;
        if ($report->delivery_issues) $score -= 10;
        if ($report->client_satisfaction === 'unsatisfied') $score -= 20;
        if ($report->cleanliness_rating === 'poor') $score -= 10;
        if ($report->cleanliness_rating === 'fair') $score -= 5;
        if (!$report->checklist_qc_checklist) $score -= 10;

        $analysis['summary_score'] = max(0, $score);

        return $analysis;
    }
}
