<?php

namespace App\Modules\Projects\Actions;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\Governance\ProjectGovernanceService;
use Illuminate\Support\Facades\Log;

class CompleteProjectAction
{
    public function __construct(
        private ProjectGovernanceService $governanceService
    ) {}

    /**
     * Attempt to mark a project as completed after validating all conditions are met.
     *
     * Conditions:
     *  1. Enquiry must be in STATUS_IN_PROGRESS.
     *  2. All selected closure tasks (handover, report) must be completed or skipped.
     *  3. No tasks may be in an in_progress state.
     *
     * @throws \Exception with a human-readable message listing what is blocking completion.
     */
    public function execute(ProjectEnquiry $enquiry, int $userId, ?string $notes = null): ProjectEnquiry
    {
        if ($enquiry->status !== EnquiryConstants::STATUS_IN_PROGRESS) {
            throw new \Exception(
                "Only in-progress projects can be marked complete. Current status: {$enquiry->status}."
            );
        }

        $user = User::find($userId);
        $isAdmin = $user && $user->hasRole(EnquiryConstants::ROLES_ADMIN);

        $readiness = $this->buildReadiness($enquiry);
        $overrodeBlockers = false;

        if (!$readiness['can_complete']) {
            $parts = [];

            if (!empty($readiness['blocking_closure_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['blocking_closure_tasks']);
                $parts[] = 'Incomplete closure tasks: ' . implode(', ', $labels);
            }

            if (!empty($readiness['in_progress_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['in_progress_tasks']);
                $parts[] = 'Tasks still in progress: ' . implode(', ', $labels);
            }

            if (!empty($readiness['pending_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['pending_tasks']);
                $parts[] = 'Tasks not yet started: ' . implode(', ', $labels);
            }

            $message = 'Cannot complete project. ' . implode('. ', $parts) . '.';

            // Admins may force completion past the closure / unfinished-task gates
            // (mirrors the admin override in EnquiryWorkflowService), but the bypass
            // is logged for the governance trail. Gate 1 (status) is never bypassable.
            if (!$isAdmin) {
                throw new \Exception($message);
            }

            $overrodeBlockers = true;
            Log::warning("Admin override: user {$userId} completing project {$enquiry->id} despite blockers. {$message}");
        }

        $this->governanceService->logEvent($enquiry, 'project_completed', $userId, [
            'notes' => $notes,
            'completed_by' => $userId,
            'admin_override' => $overrodeBlockers,
        ]);

        $enquiry->status = EnquiryConstants::STATUS_COMPLETED;
        $enquiry->save();

        Log::info("CompleteProjectAction: Enquiry {$enquiry->id} marked completed by user {$userId}." . ($overrodeBlockers ? ' (admin override)' : ''));

        return $enquiry->fresh();
    }

    /**
     * Return a transparency snapshot of whether the project can be completed and what is blocking it.
     */
    public function buildReadiness(ProjectEnquiry $enquiry): array
    {
        $selectedTasks = is_array($enquiry->selected_workflow_tasks)
            ? $enquiry->selected_workflow_tasks
            : (json_decode($enquiry->selected_workflow_tasks, true) ?? []);

        // Closure tasks that are both required AND part of this project's workflow
        $requiredClosureTypes = array_values(
            array_intersect(EnquiryConstants::PROJECT_COMPLETION_REQUISITES, $selectedTasks)
        );

        $allTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->get(['id', 'type', 'title', 'status']);

        // Closure tasks not yet done
        $blockingClosure = $allTasks
            ->whereIn('type', $requiredClosureTypes)
            ->filter(fn($t) => $t->status !== 'completed')
            ->values()
            ->map(fn($t) => ['id' => $t->id, 'type' => $t->type, 'title' => $t->title, 'status' => $t->status])
            ->toArray();

        // Any task still actively in progress (across all types)
        $inProgressTasks = $allTasks
            ->where('status', 'in_progress')
            ->values()
            ->map(fn($t) => ['id' => $t->id, 'type' => $t->type, 'title' => $t->title, 'status' => $t->status])
            ->toArray();

        // Any task not yet started. Pending tasks block completion too: every
        // selected task must be completed before the project can close.
        $pendingTasks = $allTasks
            ->where('status', 'pending')
            ->values()
            ->map(fn($t) => ['id' => $t->id, 'type' => $t->type, 'title' => $t->title, 'status' => $t->status])
            ->toArray();

        // Overall task summary for the readiness panel
        $summary = [
            'total'       => $allTasks->count(),
            'completed'   => $allTasks->where('status', 'completed')->count(),
            'in_progress' => $allTasks->where('status', 'in_progress')->count(),
            'pending'     => $allTasks->where('status', 'pending')->count(),
        ];

        $canComplete = empty($blockingClosure) && empty($inProgressTasks) && empty($pendingTasks);

        return [
            'can_complete'            => $canComplete,
            'current_status'          => $enquiry->status,
            'required_closure_tasks'  => $requiredClosureTypes,
            'blocking_closure_tasks'  => $blockingClosure,
            'in_progress_tasks'       => $inProgressTasks,
            'pending_tasks'           => $pendingTasks,
            'task_summary'            => $summary,
        ];
    }
}
