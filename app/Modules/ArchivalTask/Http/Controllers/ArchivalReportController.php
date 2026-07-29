<?php

namespace App\Modules\ArchivalTask\Http\Controllers;

use App\Modules\ArchivalTask\Services\ArchivalReportService;
use App\Modules\ArchivalTask\Models\ArchivalReport;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;

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
        $this->authorizedTask($taskId);
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
        $this->authorizedTask($taskId);
        abort_if(ArchivalReport::where('enquiry_task_id', $taskId)->exists(), 409, 'A closure report already exists for this task.');
        try {
            $this->normalizeProjectScope($request);
            $validated = $request->validate($this->getValidationRules());
            
            $report = $this->service->createReport($taskId, $validated);
            
            return response()->json([
                'data' => $report,
                'message' => 'Report created successfully'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
        $report = $this->reportForTask($taskId, $reportId);
        abort_unless($report->status === 'draft', 409, 'Submitted or approved closure reports cannot be edited.');
        try {
            $this->normalizeProjectScope($request);
            $validated = $request->validate($this->getValidationRules(false));
            
            $report = $this->service->updateReport($reportId, $validated);
            
            return response()->json([
                'data' => $report,
                'message' => 'Report updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
        $report = $this->reportForTask($taskId, $reportId);
        abort_unless($report->status === 'draft', 409, 'Only a draft closure report can be deleted.');
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
        $report = $this->reportForTask($taskId, $reportId);
        abort_unless($report->status === 'draft', 409, 'Attachments cannot be changed after submission.');
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
        $report = $this->reportForTask($taskId, $reportId);
        abort_unless($report->status === 'draft', 409, 'Attachments cannot be changed after submission.');
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
        $this->authorizedTask($taskId);
        $existing = ArchivalReport::where('enquiry_task_id', $taskId)->first();
        abort_if($existing && $existing->status !== 'draft', 409, 'A submitted or approved report cannot be re-synchronised.');
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
        $report = $this->reportForTask($taskId, $reportId);
        $request->validate([
            'status' => 'required|in:submitted,approved,returned',
            'correction_notes' => 'required_if:status,returned|nullable|string|max:2000',
        ]);

        try {
            $targetStatus = $request->input('status');
            $allowedTransition = ($report->status === 'draft' && $targetStatus === 'submitted')
                || ($report->status === 'submitted' && in_array($targetStatus, ['approved', 'returned'], true));
            if (!$allowedTransition) {
                return response()->json([
                    'message' => "Invalid closure transition from {$report->status} to {$targetStatus}.",
                ], 422);
            }

            $user = $request->user();
            if (in_array($targetStatus, ['approved', 'returned'], true)) {
                if (!$user->hasRole(['Super Admin', 'Admin', 'Project Manager'])) {
                    return response()->json(['message' => 'Only a Project Manager or administrator may review project closure.'], 403);
                }
            }
            if (in_array($targetStatus, ['submitted', 'approved'], true)) {
                $missingChecks = count($this->service->getMissingRequiredChecks($taskId, $report));

                if ($missingChecks || !$report->archive_reference || !$report->archive_location
                ) {
                    return response()->json([
                        'message' => 'Complete the closure checks and archive details before submission.',
                    ], 422);
                }
            }

            $report = $this->service->changeStatus(
                $reportId,
                $targetStatus,
                $user,
                $request->input('correction_notes')
            );
            $reportData = $this->service->getReportByTask($taskId);
            
            return response()->json([
                'data' => $reportData,
                'message' => $targetStatus === 'returned'
                    ? 'Closure report returned for correction.'
                    : 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate PDF for the report
     */
    public function generatePdf(int $taskId, int $reportId)
    {
        $this->reportForTask($taskId, $reportId);
        try {
            $report = $this->service->getReportById($reportId);
            
            if (!$report) {
                return response()->json(['message' => 'Report not found'], 404);
            }

            $pdf = Pdf::loadView('reports.archival', [
                'report' => $report,
                'financialSummary' => $this->service->getFinancialSummary($taskId),
                'systemDocuments' => $this->service->getSystemDocuments($taskId),
                'closureContext' => $this->service->getClosureContext($taskId),
                'handoverSummary' => $this->service->getHandoverSummary($taskId),
            ]);
            
            $reference = preg_replace('/[^A-Za-z0-9_-]+/', '-', $report->project_code ?: (string) $reportId);
            return $pdf->download('project-closure-' . trim($reference, '-') . '.pdf');
        } catch (\Exception $e) {
             return response()->json([
                'message' => 'Failed to generate PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analyze report and generate insights
     */
    public function analyze(int $taskId, int $reportId): JsonResponse
    {
        $this->reportForTask($taskId, $reportId);
        try {
            $analysis = $this->service->analyzeReport($reportId);
            
            return response()->json([
                'data' => $analysis,
                'message' => 'Report analyzed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to analyze report',
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
            
            // Checklist
            'checklist_ppt' => 'nullable|boolean',
            'checklist_cutlist' => 'nullable|boolean',
            'checklist_site_survey_form' => 'nullable|boolean',
            'checklist_project_budget_file' => 'nullable|boolean',
            'checklist_material_list' => 'nullable|boolean',
            'checklist_qc_checklist' => 'nullable|boolean',
            'checklist_setup_setdown' => 'nullable|boolean',
            'checklist_client_feedback' => 'nullable|boolean',
            
            // Record Management
            'archive_reference' => 'nullable|string',
            'archive_location' => 'nullable|string',
            'retention_period' => 'nullable|string',
            
            // Related data
            'setup_items' => 'nullable|array',
            'setup_items.*.deliverable_item' => 'nullable|string|max:255',
            'setup_items.*.assigned_technician' => 'nullable|string|max:255',
            'setup_items.*.site_section' => 'nullable|string|max:255',
            'setup_items.*.status' => 'nullable|in:set,pending',
            'setup_items.*.placement_accuracy' => 'nullable|string|max:50',
            'setup_items.*.notes' => 'nullable|string',
            
            'item_placements' => 'nullable|array',
            'item_placements.*.section_area' => 'nullable|string|max:255',
            'item_placements.*.items_installed' => 'nullable|string',
            'item_placements.*.placement_accuracy' => 'nullable|in:correct,needs_adjusted',
            'item_placements.*.observation' => 'nullable|string',
        ];

        return $rules;
    }

    private function normalizeProjectScope(Request $request): void
    {
        $scope = $request->input('project_scope');
        if (!is_array($scope)) {
            return;
        }

        $request->merge([
            'project_scope' => collect($scope)
                ->map(fn ($item) => is_array($item) ? ($item['name'] ?? null) : $item)
                ->filter()
                ->implode("\n"),
        ]);
    }

    private function authorizedTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::findOrFail($taskId);
        abort_unless($task->type === 'report', 404, 'Closure task not found.');
        abort_unless($task->isUserAuthorized(request()->user()), 403, 'You are not authorized to manage this closure report.');

        return $task;
    }

    private function reportForTask(int $taskId, int $reportId): ArchivalReport
    {
        $this->authorizedTask($taskId);

        return ArchivalReport::where('enquiry_task_id', $taskId)->findOrFail($reportId);
    }
}
