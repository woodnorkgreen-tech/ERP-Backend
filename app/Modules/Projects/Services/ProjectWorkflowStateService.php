<?php

namespace App\Modules\Projects\Services;

use App\Constants\EnquiryConstants;
use App\Models\ProjectEnquiry;
use App\Modules\Projects\Actions\CompleteProjectAction;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Collection;

class ProjectWorkflowStateService
{
    private const FINANCE_GATED_TASK_TYPES = ['procurement', 'production', 'logistics', 'setup', 'setdown'];

    public function __construct(
        private FinanceService $financeService,
        private CompleteProjectAction $completionAction
    ) {}

    public function forEnquiry(ProjectEnquiry $enquiry): array
    {
        $enquiry->loadMissing([
            'client',
            'project',
            'projectOfficer',
            'payments',
            'enquiryTasks.department',
            'enquiryTasks.assignedUser.department',
            'enquiryTasks.assignedUsers',
        ]);

        $tasks = $enquiry->enquiryTasks
            ->sortBy(fn (EnquiryTask $task) => [$task->task_order ?? 999, $task->id])
            ->values();

        $taskTypes = $tasks->keyBy('type');
        $financeGate = $this->financeGate($enquiry, $tasks);
        $completion = $this->completionAction->buildReadiness($enquiry);
        $closure = $this->completionAction->buildClosureReadiness($enquiry);
        $taskStates = $tasks->map(fn (EnquiryTask $task) => $this->taskState($task, $taskTypes, $financeGate))->values();
        $blockedTasks = $taskStates->filter(fn (array $task) => $task['is_blocked'])->values();

        return [
            'enquiry' => [
                'id' => $enquiry->id,
                'title' => $enquiry->title,
                'status' => $enquiry->status,
                'enquiry_number' => $enquiry->enquiry_number,
                'job_number' => $enquiry->job_number,
                'workflow_preset_type' => $enquiry->workflow_preset_type,
                'project_officer' => $enquiry->projectOfficer ? [
                    'id' => $enquiry->projectOfficer->id,
                    'name' => $enquiry->projectOfficer->name,
                ] : null,
            ],
            'project' => $enquiry->project ? [
                'id' => $enquiry->project->id,
                'project_id' => $enquiry->project->project_id,
                'status' => $enquiry->project->status,
                'start_date' => optional($enquiry->project->start_date)->toDateString(),
                'end_date' => optional($enquiry->project->end_date)->toDateString(),
            ] : null,
            'summary' => $this->summary($tasks, $blockedTasks),
            'finance_gate' => $financeGate,
            'completion' => [
                'can_complete' => $completion['can_complete'],
                'current_status' => $completion['current_status'],
                'task_summary' => $completion['task_summary'],
                'blocking_closure_tasks' => $completion['blocking_closure_tasks'],
                'in_progress_tasks' => $completion['in_progress_tasks'],
                'pending_tasks' => $completion['pending_tasks'],
            ],
            'closure' => [
                'can_close' => $closure['can_close'],
                'current_status' => $closure['current_status'],
                'required_closure_tasks' => $closure['required_closure_tasks'],
                'missing_closure_tasks' => $closure['missing_closure_tasks'],
                'blocking_closure_tasks' => $closure['blocking_closure_tasks'],
            ],
            'tasks' => $taskStates,
            'blocked_tasks' => $blockedTasks,
            'next_action' => $this->nextAction($enquiry, $taskStates, $financeGate, $completion, $closure, $blockedTasks),
        ];
    }

    private function financeGate(ProjectEnquiry $enquiry, Collection $tasks): array
    {
        $progress = $this->financeService->getPaymentProgress($enquiry);
        $isBypassed = in_array($enquiry->workflow_preset_type, ['internal_job', 'sponsorship'], true);
        $isReleased = (bool) ($enquiry->finance_released ?? false) || in_array($enquiry->status, [
            EnquiryConstants::STATUS_PLANNING,
            EnquiryConstants::STATUS_IN_PROGRESS,
            EnquiryConstants::STATUS_COMPLETED,
            EnquiryConstants::STATUS_CLOSED,
        ], true);
        $hasGatedTasks = $tasks->contains(fn (EnquiryTask $task) => in_array($task->type, self::FINANCE_GATED_TASK_TYPES, true));
        $isMet = (bool) ($progress['is_70_percent_met'] ?? false);
        $authorized = $isBypassed || $isReleased || $isMet || !$hasGatedTasks;

        return [
            'applies' => $hasGatedTasks && !$isBypassed,
            'authorized' => $authorized,
            'is_bypassed' => $isBypassed,
            'is_released' => $isReleased,
            'threshold_percentage' => 70,
            'progress' => $progress,
            'blocked_task_types' => $authorized ? [] : self::FINANCE_GATED_TASK_TYPES,
            'message' => $this->financeGateMessage($authorized, $isBypassed, $isReleased, $isMet, $hasGatedTasks, $progress),
        ];
    }

    private function financeGateMessage(bool $authorized, bool $isBypassed, bool $isReleased, bool $isMet, bool $hasGatedTasks, array $progress): string
    {
        if (!$hasGatedTasks) {
            return 'No finance-gated operational tasks are selected for this workflow.';
        }

        if ($isBypassed) {
            return 'Internal or sponsorship workflow: finance gate bypassed.';
        }

        if ($isReleased) {
            return 'Finance gate released for operations.';
        }

        if ($isMet) {
            return '70% mobilization threshold met.';
        }

        $percentage = $progress['percentage'] ?? 0;
        return "Finance gate locked: {$percentage}% received. Minimum 70% mobilization is required before operational tasks proceed.";
    }

    private function taskState(EnquiryTask $task, Collection $taskTypes, array $financeGate): array
    {
        $blockedBy = $this->dependencyBlockers($task, $taskTypes);
        $isFinanceGated = in_array($task->type, self::FINANCE_GATED_TASK_TYPES, true);
        $financeBlocked = $isFinanceGated && !$financeGate['authorized'];
        $isOpen = !in_array($task->status, ['completed', 'cancelled'], true);
        $isBlocked = $isOpen && (!empty($blockedBy) || $financeBlocked);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'status' => $task->status,
            'phase' => $this->phaseFor($task->type),
            'task_order' => $task->task_order,
            'priority' => $task->priority,
            'due_date' => optional($task->due_date)->toDateString(),
            'department' => $task->department ? [
                'id' => $task->department->id,
                'name' => $task->department->name,
            ] : null,
            'assigned_user' => $task->assignedUser ? [
                'id' => $task->assignedUser->id,
                'name' => $task->assignedUser->name,
                'department' => $task->assignedUser->department?->name,
            ] : null,
            'is_authorized' => (bool) $task->is_authorized,
            'is_blocked' => $isBlocked,
            'blocked_by' => $blockedBy,
            'gate' => [
                'type' => $financeBlocked ? 'financial' : null,
                'message' => $financeBlocked ? $financeGate['message'] : null,
            ],
        ];
    }

    private function dependencyBlockers(EnquiryTask $task, Collection $taskTypes): array
    {
        $prerequisites = config('enquiry_workflow.task_dependencies', [])[$task->type] ?? [];
        $blockedBy = [];

        foreach ($prerequisites as $type) {
            $prerequisite = $taskTypes->get($type);
            if ($prerequisite && $prerequisite->status !== 'completed') {
                $blockedBy[] = $prerequisite->title;
            }
        }

        return $blockedBy;
    }

    private function phaseFor(string $type): string
    {
        foreach (config('enquiry_workflow.task_templates', []) as $template) {
            if (($template['type'] ?? null) === $type) {
                return $template['phase'] ?? 'Other';
            }
        }

        return 'Other';
    }

    private function summary(Collection $tasks, Collection $blockedTasks): array
    {
        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $tasks->where('status', 'in_progress')->count(),
            'pending' => $tasks->where('status', 'pending')->count(),
            'cancelled' => $tasks->where('status', 'cancelled')->count(),
            'blocked' => $blockedTasks->count(),
            'progress_percentage' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
        ];
    }

    private function nextAction(ProjectEnquiry $enquiry, Collection $tasks, array $financeGate, array $completion, array $closure, Collection $blockedTasks): array
    {
        if ($enquiry->status === EnquiryConstants::STATUS_CLOSED) {
            return $this->action('project_closed', 'Project closed', 'No further workflow action is required.', 'Project Office', 'success');
        }

        if ($enquiry->status === EnquiryConstants::STATUS_COMPLETED) {
            if ($closure['can_close']) {
                return $this->action('close_project', 'Close project', 'Handover and report are complete. Move the project to Closed.', 'Project Manager', 'ready');
            }

            return $this->action('complete_closure_tasks', 'Finish closure tasks', 'Complete handover and report before closing the project.', 'Project Office', 'warning');
        }

        if (!$enquiry->project_officer_id) {
            return $this->action('assign_project_officer', 'Assign project officer', 'A project officer is required before ownership is clear.', 'Project Management', 'warning');
        }

        if (!$financeGate['authorized'] && $financeGate['applies']) {
            return $this->action('resolve_finance_gate', 'Resolve finance gate', $financeGate['message'], 'Accounts', 'blocked');
        }

        if ($completion['can_complete'] && in_array($enquiry->status, [EnquiryConstants::STATUS_PLANNING, EnquiryConstants::STATUS_IN_PROGRESS], true)) {
            return $this->action('complete_project', 'Complete project', 'Operational work is complete. Move delivery to Completed.', 'Project Manager', 'ready');
        }

        if ($blockedTasks->isNotEmpty()) {
            $task = $blockedTasks->first();
            $reason = !empty($task['blocked_by'])
                ? 'Waiting for: ' . implode(', ', $task['blocked_by'])
                : ($task['gate']['message'] ?? 'Blocked by workflow rules.');

            return $this->action('unblock_task', "Unblock {$task['title']}", $reason, $task['department']['name'] ?? 'Project Team', 'blocked', $task);
        }

        $nextTask = $tasks->first(fn (array $task) => in_array($task['status'], ['in_progress', 'pending'], true) && !$task['is_blocked']);
        if ($nextTask) {
            if (!$nextTask['assigned_user']) {
                return $this->action('assign_task', "Assign {$nextTask['title']}", 'This task is ready but has no accountable owner.', $nextTask['department']['name'] ?? 'Project Team', 'warning', $nextTask);
            }

            $verb = $nextTask['status'] === 'in_progress' ? 'Complete' : 'Start';
            return $this->action('work_task', "{$verb} {$nextTask['title']}", 'This is the next ready workflow task.', $nextTask['assigned_user']['name'], 'ready', $nextTask);
        }

        if ($tasks->isEmpty()) {
            return $this->action('configure_workflow', 'Configure workflow', 'No project tasks exist yet for this enquiry.', 'Project Management', 'warning');
        }

        return $this->action('monitor_project', 'Monitor project', 'No immediate blocker or ready task was detected.', 'Project Team', 'neutral');
    }

    private function action(string $type, string $label, string $message, string $owner, string $severity, ?array $task = null): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'message' => $message,
            'owner' => $owner,
            'severity' => $severity,
            'task' => $task ? [
                'id' => $task['id'],
                'title' => $task['title'],
                'type' => $task['type'],
                'status' => $task['status'],
            ] : null,
        ];
    }
}
