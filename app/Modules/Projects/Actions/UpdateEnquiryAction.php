<?php

namespace App\Modules\Projects\Actions;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Resources\EnquiryResource;
use App\Modules\Projects\Services\EnquiryWorkflowService;
use App\Modules\Projects\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Updates a ProjectEnquiry. Extracted verbatim from EnquiryController::update()
 * (was ~170 inline lines) — completion/closure are gated lifecycle transitions
 * handled here rather than as a plain field write, so they keep their own audit
 * trail instead of silently falling through to the generic update path.
 */
class UpdateEnquiryAction
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function execute(Request $request, ProjectEnquiry $enquiry, CompleteProjectAction $completionAction): JsonResponse
    {
        if ($request->has('enquiry_title') && !$request->has('title')) {
            $request->merge(['title' => $request->enquiry_title]);
        }

        // Completion and closure are gated lifecycle actions. Keep direct status
        // updates from bypassing the dedicated audit trails.
        if ($request->input('status') === EnquiryConstants::STATUS_COMPLETED) {
            $readiness = $completionAction->buildReadiness($enquiry);
            $user      = Auth::user();

            if (!$readiness['can_complete']) {
                // Push a persistent, actionable notification to the user's notification center
                $this->notificationService->sendProjectCompletionBlocked($enquiry, $user, $readiness);

                $lines = [];
                foreach ($readiness['in_progress_tasks'] as $task) {
                    $lines[] = [
                        'type'     => 'in_progress_task',
                        'task_id'  => $task['id'],
                        'title'    => $task['title'],
                        'status'   => $task['status'],
                        'action'   => "\"{$task['title']}\" is still in progress — finish or skip it first.",
                        'severity' => 'warning',
                    ];
                }

                return response()->json([
                    'message'        => 'This project cannot be marked complete yet. A notification has been added to your notification center with a full checklist.',
                    'hint'           => 'Resolve all operational items below, then use POST /enquiries/{id}/complete.',
                    'can_complete'   => false,
                    'task_summary'   => $readiness['task_summary'],
                    'blocking_items' => $lines,
                ], 422);
            }

            // All conditions already met — redirect to the dedicated endpoint (no notification needed)
            return response()->json([
                'message'        => 'All delivery-completion conditions are met. Use POST /enquiries/{id}/complete — the action will be recorded in the governance log.',
                'can_complete'   => true,
                'task_summary'   => $readiness['task_summary'],
                'blocking_items' => [],
            ], 200);
        }

        if ($request->input('status') === EnquiryConstants::STATUS_CLOSED) {
            $readiness = $completionAction->buildClosureReadiness($enquiry);

            if (!$readiness['can_close']) {
                return response()->json([
                    'message'        => 'This project cannot be closed yet. Complete handover and report first.',
                    'hint'           => 'Resolve all closure items, then use POST /enquiries/{id}/close.',
                    'can_close'      => false,
                    'blocking_items' => [
                        'missing_closure_tasks'  => $readiness['missing_closure_tasks'],
                        'blocking_closure_tasks' => $readiness['blocking_closure_tasks'],
                    ],
                ], 422);
            }

            return response()->json([
                'message'        => 'All closure conditions are met. Use POST /enquiries/{id}/close to finalise closure.',
                'can_close'      => true,
                'blocking_items' => [],
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'date_received' => 'sometimes|required|date',
            'expected_delivery_date' => 'sometimes|nullable|date|after_or_equal:date_received',
            'client_id' => 'sometimes|required|integer|exists:clients,id',
            'title' => 'sometimes|required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'project_scope' => 'nullable',
            'priority' => 'nullable|string|in:' . implode(',', EnquiryConstants::getAllPriorities()),
            'contact_person' => 'sometimes|nullable|string|max:255',
            'project_officer_id' => 'nullable|integer|exists:users,id',
            'status' => 'sometimes|required|string|in:' . implode(',', EnquiryConstants::getAllStatuses()),
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_department' => 'nullable|string|max:255',
            'assigned_po' => 'nullable|integer|exists:users,id',
            'follow_up_notes' => 'nullable|string',
            'venue' => 'nullable|string|max:255',
            'site_survey_skipped' => 'boolean',
            'site_survey_skip_reason' => 'nullable|string|required_if:site_survey_skipped,true',
            'selected_workflow_tasks' => 'nullable|array',
            'workflow_preset_type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate project officer role if provided
        if ($request->has('project_officer_id') && $request->project_officer_id) {
            $user = User::find($request->project_officer_id);
            if (!$user || !$user->hasRole(['Project Officer', 'Project Manager'])) {
                return response()->json([
                    'message' => 'Invalid project officer assignment',
                    'errors' => ['project_officer_id' => ['Selected user is not a valid project officer']]
                ], 422);
            }
        }

        // Check if Project Officer changed and send notification
        if ($request->has('project_officer_id') && $request->project_officer_id) {
            $oldPO = $enquiry->getOriginal('project_officer_id');
            $newPO = $request->project_officer_id;

            if ($oldPO !== $newPO) {
                // PO has changed, send notification to new PO
                $newProjectOfficer = User::find($newPO);
                if ($newProjectOfficer) {
                    try {
                        $this->notificationService->sendProjectOfficerAssigned(
                            $enquiry->fresh(['client', 'creator']),
                            $newProjectOfficer,
                            Auth::user()
                        );
                    } catch (\Exception $e) {
                        Log::error("Failed to send PO assignment notification: " . $e->getMessage());
                    }
                }
            }
        }

        return DB::transaction(function () use ($request, $enquiry) {
            $enquiry->update($request->only([
                'date_received',
                'expected_delivery_date',
                'client_id',
                'title',
                'description',
                'project_scope',
                'priority',
                'contact_person',
                'project_officer_id',
                'status',
                'department_id',
                'assigned_department',
                'assigned_po',
                'follow_up_notes',
                'venue',
                'site_survey_skipped',
                'site_survey_skip_reason',
                'selected_workflow_tasks',
                'workflow_preset_type',
            ]));

            if ($request->has('status')) {
                $projectStatus = $request->input('status');
                if (in_array($projectStatus, ['planning', 'in_progress', 'completed', 'closed', 'cancelled'], true)) {
                    $enquiry->project()->update(['status' => $projectStatus]);
                }
            }

            // Sync workflow tasks (create any newly selected tasks)
            app(EnquiryWorkflowService::class)->initializeWorkflow($enquiry);

            return response()->json([
                'message' => 'Enquiry updated successfully',
                'data'    => new EnquiryResource($enquiry->load('client', 'department', 'projectOfficer', 'deliverables')),
            ]);
        });
    }
}
