<?php

namespace App\Modules\ArchivalTask\Http\Controllers;

use App\Modules\ArchivalTask\Services\ArchivalReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ArchivalReportController extends Controller
{
    protected ArchivalReportService $service;

    public function __construct(ArchivalReportService $service)
    {
        $this->service = $service;
    }

    /**
     * Get archival report for a task
     */
    public function index(int $taskId): JsonResponse
    {
        try {
            $report = $this->service->getReportByTask($taskId);
            
            return response()->json([
                'data' => $report,
                'message' => $report ? 'Report retrieved successfully' : 'No report found for this task'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new archival report
     */
    public function store(Request $request, int $taskId): JsonResponse
    {
        try {
            $validated = $request->validate($this->getValidationRules());
            
            $report = $this->service->createReport($taskId, $validated);
            
            return response()->json([
                'data' => $report,
                'message' => 'Report created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an archival report
     */
    public function update(Request $request, int $taskId, int $reportId): JsonResponse
    {
        try {
            $validated = $request->validate($this->getValidationRules(false));
            
            $report = $this->service->updateReport($reportId, $validated);
            
            return response()->json([
                'data' => $report,
                'message' => 'Report updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an archival report
     */
    public function destroy(int $taskId, int $reportId): JsonResponse
    {
        try {
            $this->service->deleteReport($reportId);
            
            return response()->json([
                'message' => 'Report deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload attachment to report
     */
    public function uploadAttachment(Request $request, int $taskId, int $reportId): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:10240', // 10MB max
                'category' => 'required|string',
            ]);

            $attachment = $this->service->uploadAttachment(
                $reportId,
                $request->file('file'),
                $request->input('category')
            );
            
            return response()->json([
                'data' => $attachment,
                'message' => 'Attachment uploaded successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload attachment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete attachment from report
     */
    public function deleteAttachment(int $taskId, int $reportId, string $attachmentId): JsonResponse
    {
        try {
            $success = $this->service->deleteAttachment($reportId, $attachmentId);
            
            if (!$success) {
                return response()->json([
                    'message' => 'Attachment not found'
                ], 404);
            }
            
            return response()->json([
                'message' => 'Attachment deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete attachment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Auto-populate report data from other tasks
     */
    public function autoPopulate(int $taskId): JsonResponse
    {
        try {
            $data = $this->service->autoPopulateData($taskId);
            
            return response()->json([
                'data' => $data,
                'message' => 'Data populated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to populate data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change report status
     */
    public function changeStatus(Request $request, int $taskId, int $reportId): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'required|in:draft,submitted,approved',
            ]);

            $report = $this->service->changeStatus($reportId, $request->input('status'));
            
            return response()->json([
                'data' => $report,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get validation rules
     */
    protected function getValidationRules(bool $isCreate = true): array
    {
        $rules = [
            // Section 1: Project Information
            'client_name' => 'nullable|string|max:255',
            'project_code' => 'nullable|string|max:100',
            'project_officer' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'site_location' => 'nullable|string',
            
            // Section 2: Project Scope
            'project_scope' => 'nullable|string',
            
            // Section 3: Procurement
            'materials_mrf_attached' => 'nullable|boolean',
            'items_sourced_externally' => 'nullable|string',
            'procurement_challenges' => 'nullable|string',
            
            // Section 4: Fabrication
            'production_start_date' => 'nullable|date',
            'packaging_labeling_status' => 'nullable|string|max:100',
            'materials_used_in_production' => 'nullable|string',
            
            // Section 5: Team & Setup
            'team_captain' => 'nullable|string|max:255',
            'setup_team_assigned' => 'nullable|string',
            'branding_team_assigned' => 'nullable|string',
            'all_deliverables_available' => 'nullable|boolean',
            'setup_aligned_to_schedule' => 'nullable|boolean',
            'delays_occurred' => 'nullable|boolean',
            'delay_reasons' => 'nullable|string',
            'deliverables_checked' => 'nullable|boolean',
            'site_organization' => 'nullable|in:excellent,good,fair,poor',
            'cleanliness_rating' => 'nullable|in:excellent,good,fair,poor',
            'general_findings' => 'nullable|string',
            'site_readiness_notes' => 'nullable|string',
            
            // Section 6: Client Handover
            'handover_date' => 'nullable|date',
            'client_rating' => 'nullable|string|max:50',
            'client_remarks' => 'nullable|string',
            'print_clarity_rating' => 'nullable|in:good,fair,poor,n/a',
            'printworks_accuracy_rating' => 'nullable|in:good,fair,poor,n/a',
            'installation_precision_comments' => 'nullable|string',
            'setup_speed_flow' => 'nullable|in:good,fair,poor',
            'team_coordination' => 'nullable|in:good,fair,poor',
            'efficiency_remarks' => 'nullable|string',
            'client_kept_informed' => 'nullable|boolean',
            'client_satisfaction' => 'nullable|in:satisfied,unsatisfied,n/a',
            'communication_comments' => 'nullable|string',
            'delivered_on_schedule' => 'nullable|boolean',
            'delivery_condition' => 'nullable|in:good,fair,poor',
            'delivery_issues' => 'nullable|boolean',
            'delivery_notes' => 'nullable|string',
            'team_professionalism' => 'nullable|in:good,fair,poor',
            'client_confidence' => 'nullable|boolean',
            'professionalism_feedback' => 'nullable|string',
            'recommendations_action_points' => 'nullable|string',
            
            // Section 7: Set-Down
            'setdown_date' => 'nullable|date',
            'items_condition_returned' => 'nullable|string',
            'site_clearance_status' => 'nullable|string|max:100',
            'outstanding_items' => 'nullable|string',
            
            // Section 9: Signatures
            'project_officer_signature' => 'nullable|string|max:255',
            'project_officer_sign_date' => 'nullable|date',
            'reviewed_by' => 'nullable|string|max:255',
            'reviewer_sign_date' => 'nullable|date',
            
            // Status
            'status' => 'nullable|in:draft,submitted,approved',
            
            // Related data
            'setup_items' => 'nullable|array',
            'setup_items.*.deliverable_item' => 'nullable|string|max:255',
            'setup_items.*.assigned_technician' => 'nullable|string|max:255',
            'setup_items.*.site_section' => 'nullable|string|max:255',
            'setup_items.*.status' => 'nullable|in:set,pending',
            'setup_items.*.notes' => 'nullable|string',
            
            'item_placements' => 'nullable|array',
            'item_placements.*.section_area' => 'nullable|string|max:255',
            'item_placements.*.items_installed' => 'nullable|string',
            'item_placements.*.placement_accuracy' => 'nullable|in:correct,needs_adjusted',
            'item_placements.*.observation' => 'nullable|string',
        ];

        return $rules;
    }
}
