<?php

namespace App\Modules\Projects\Actions;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Projects\Models\EnquiryTask;
use App\Services\Governance\ProjectGovernanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteProjectAction
{
    public function __construct(
        private ProjectGovernanceService $governanceService
    ) {}

    /**
     * Mark delivery as completed after validating operational work is done.
     *
     * Conditions:
     *  1. Enquiry must be in STATUS_PLANNING or STATUS_IN_PROGRESS.
     *  2. No non-closure task may be pending or in progress.
     *
     * @throws \Exception with a human-readable message listing what is blocking completion.
     */
    public function execute(ProjectEnquiry $enquiry, int $userId, ?string $notes = null): ProjectEnquiry
    {
        $completableStatuses = [
            EnquiryConstants::STATUS_PLANNING,
            EnquiryConstants::STATUS_IN_PROGRESS,
        ];

        if (!in_array($enquiry->status, $completableStatuses, true)) {
            throw new \Exception(
                "Only planning or in-progress projects can be marked complete. Current status: {$enquiry->status}."
            );
        }

        $user = User::find($userId);
        $isAdmin = $user && $user->hasRole(EnquiryConstants::ROLES_ADMIN);

        $readiness = $this->buildReadiness($enquiry);
        $overrodeBlockers = false;

        if (!$readiness['can_complete']) {
            $parts = [];

            if (!empty($readiness['in_progress_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['in_progress_tasks']);
                $parts[] = 'Tasks still in progress: ' . implode(', ', $labels);
            }

            if (!empty($readiness['pending_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['pending_tasks']);
                $parts[] = 'Tasks not yet started: ' . implode(', ', $labels);
            }

            $message = 'Cannot complete project. ' . implode('. ', $parts) . '.';

            // Admins may force completion past unfinished-task gates, but the
            // bypass is logged for the governance trail. The lifecycle-status
            // gate above is never bypassable.
            if (!$isAdmin) {
                throw new \Exception($message);
            }

            $overrodeBlockers = true;
            Log::warning("Admin override: user {$userId} completing project {$enquiry->id} despite blockers. {$message}");
        }

        return DB::transaction(function () use ($enquiry, $userId, $notes, $overrodeBlockers) {
            $this->governanceService->logEvent($enquiry, 'project_completed', $userId, [
                'notes'          => $notes,
                'completed_by'   => $userId,
                'admin_override' => $overrodeBlockers,
            ]);

            $enquiry->status = EnquiryConstants::STATUS_COMPLETED;
            $enquiry->save();
            $enquiry->project()->update(['status' => EnquiryConstants::STATUS_COMPLETED]);

            Log::info("CompleteProjectAction: Enquiry {$enquiry->id} marked completed by user {$userId}." . ($overrodeBlockers ? ' (admin override)' : ''));

            return $enquiry->fresh();
        });
    }

    /**
     * Close a completed project after formal handover and reporting are done.
     */
    public function close(ProjectEnquiry $enquiry, int $userId, ?string $notes = null): ProjectEnquiry
    {
        if ($enquiry->status !== EnquiryConstants::STATUS_COMPLETED) {
            throw new \Exception(
                "Only completed projects can be closed. Current status: {$enquiry->status}."
            );
        }

        $readiness = $this->buildClosureReadiness($enquiry);

        if (!$readiness['can_close']) {
            $parts = [];

            if (!empty($readiness['missing_closure_tasks'])) {
                $parts[] = 'Missing closure tasks: ' . implode(', ', $readiness['missing_closure_tasks']);
            }

            if (!empty($readiness['blocking_closure_tasks'])) {
                $labels = array_map(fn($t) => "{$t['title']} ({$t['type']})", $readiness['blocking_closure_tasks']);
                $parts[] = 'Incomplete closure tasks: ' . implode(', ', $labels);
            }

            throw new \Exception('Cannot close project. ' . implode('. ', $parts) . '.');
        }

        return DB::transaction(function () use ($enquiry, $userId, $notes) {
            $this->governanceService->logEvent($enquiry, 'project_closed', $userId, [
                'notes'     => $notes,
                'closed_by' => $userId,
            ]);

            $enquiry->status = EnquiryConstants::STATUS_CLOSED;
            $enquiry->save();
            $enquiry->project()->update(['status' => EnquiryConstants::STATUS_CLOSED]);

            Log::info("CompleteProjectAction: Enquiry {$enquiry->id} closed by user {$userId}.");

            return $enquiry->fresh();
        });
    }

    /**
     * Return whether delivery can be marked complete and what is blocking it.
     */
    public function buildReadiness(ProjectEnquiry $enquiry): array
    {
        $closureTypes = EnquiryConstants::PROJECT_CLOSURE_REQUISITES;

        $allTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->get(['id', 'type', 'title', 'status']);

        $operationalTasks = $allTasks->reject(fn($t) => in_array($t->type, $closureTypes, true));

        // Any operational task still actively in progress.
        $inProgressTasks = $operationalTasks
            ->where('status', 'in_progress')
            ->values()
            ->map(fn($t) => ['id' => $t->id, 'type' => $t->type, 'title' => $t->title, 'status' => $t->status])
            ->toArray();

        // Pending operational tasks block delivery completion. Closure tasks
        // are handled by the separate closed-project gate.
        $pendingTasks = $operationalTasks
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

        $canComplete = empty($inProgressTasks) && empty($pendingTasks);

        return [
            'can_complete'            => $canComplete,
            'current_status'          => $enquiry->status,
            'required_closure_tasks'  => $closureTypes,
            'blocking_closure_tasks'  => [],
            'in_progress_tasks'       => $inProgressTasks,
            'pending_tasks'           => $pendingTasks,
            'task_summary'            => $summary,
        ];
    }

    /**
     * Return whether the completed project can move to the formal Closed tab.
     */
    public function buildClosureReadiness(ProjectEnquiry $enquiry): array
    {
        $requiredClosureTypes = EnquiryConstants::PROJECT_CLOSURE_REQUISITES;

        $allTasks = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->get(['id', 'type', 'title', 'status']);

        $closureTasks = $allTasks->whereIn('type', $requiredClosureTypes);
        $presentTypes = $closureTasks->pluck('type')->unique()->values()->all();
        $missingTypes = array_values(array_diff($requiredClosureTypes, $presentTypes));

        $blockingClosure = $closureTasks
            ->filter(fn($t) => $t->status !== 'completed')
            ->values()
            ->map(fn($t) => ['id' => $t->id, 'type' => $t->type, 'title' => $t->title, 'status' => $t->status])
            ->toArray();

        return [
            'can_close'              => empty($missingTypes) && empty($blockingClosure),
            'current_status'         => $enquiry->status,
            'required_closure_tasks' => $requiredClosureTypes,
            'missing_closure_tasks'  => $missingTypes,
            'blocking_closure_tasks' => $blockingClosure,
        ];
    }
}
