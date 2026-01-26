<?php

namespace App\Services;

use App\Models\ProjectEnquiry;
use App\Repositories\EnquiryRepository;
use App\Repositories\WorkflowRepository;
use Illuminate\Support\Facades\Auth;

class EnquiryService
{
    protected $enquiryRepository;
    protected $workflowRepository;

    public function __construct(EnquiryRepository $enquiryRepository, WorkflowRepository $workflowRepository)
    {
        $this->enquiryRepository = $enquiryRepository;
        $this->workflowRepository = $workflowRepository;
    }

    public function createEnquiry(array $data): ProjectEnquiry
    {
        $data['enquiry_number'] = $this->generateEnquiryNumber();
        $data['created_by'] = Auth::id();

        $enquiry = $this->enquiryRepository->create($data);

        // Start workflow
        $this->startWorkflowForEnquiry($enquiry);

        // Dispatch event
        \App\Events\EnquiryCreated::dispatch($enquiry);

        return $enquiry;
    }

    public function updateEnquiry(ProjectEnquiry $enquiry, array $data): ProjectEnquiry
    {
        $this->enquiryRepository->update($enquiry, $data);

        // Sync workflow tasks if selected_workflow_tasks is updated
        if (isset($data['selected_workflow_tasks'])) {
            $this->syncWorkflowTasks($enquiry, $data['selected_workflow_tasks']);
        }

        return $enquiry->fresh();
    }

    private function syncWorkflowTasks(ProjectEnquiry $enquiry, array $selectedTasks)
    {
        // Refresh relation to get latest state
        $existingTasks = $enquiry->enquiryTasks()->get();
        
        // Define canonical order for tasks
        $canonicalOrder = [
            'site-survey',
            'design',
            'materials',
            'budget',
            'quote',
            'approval',
            'procurement',
            'production',
            'logistics',
            'setup',
            'teams',
            'setdown',
            'project_management'
        ];

        // Sort selected tasks based on canonical order
        // Tasks not in the canonical list will be appended at the end
        usort($selectedTasks, function ($a, $b) use ($canonicalOrder) {
            $posA = array_search($a, $canonicalOrder);
            $posB = array_search($b, $canonicalOrder);

            $posA = ($posA === false) ? 999 : $posA;
            $posB = ($posB === false) ? 999 : $posB;

            return $posA - $posB;
        });
        
        // 1. Delete tasks not in selection (Skip completed tasks to preserve history)
        $tasksToDelete = $existingTasks->whereNotIn('type', $selectedTasks);
        foreach ($tasksToDelete as $task) {
             if ($task->status !== 'completed') {
                 $task->delete();
             }
        }

        // 2. Create or Update tasks
        foreach ($selectedTasks as $index => $taskType) {
            $existingTask = $existingTasks->firstWhere('type', $taskType);
            
            if ($existingTask) {
                // Update order
                $existingTask->update(['task_order' => $index + 1]);
            } else {
                // Create new task
                $departmentId = $this->resolveDepartmentIdForTaskType($taskType);
                
                \App\Modules\Projects\Models\EnquiryTask::create([
                    'project_enquiry_id' => $enquiry->id,
                    'type' => $taskType,
                    'title' => ucwords(str_replace(['_', '-'], ' ', $taskType)) . ' Task',
                    'status' => 'pending',
                    'task_order' => $index + 1,
                    'department_id' => $departmentId,
                    'created_by' => Auth::id()
                ]);
            }
        }
    }

    private function resolveDepartmentIdForTaskType($type)
    {
        $mapping = \App\Modules\Projects\Models\EnquiryTask::TASK_TYPE_DEPARTMENT_MAPPING;
        // Handle generic cases if not in mapping
        $deptName = $mapping[$type] ?? null;
        
        if ($deptName) {
            $dept = \App\Modules\HR\Models\Department::where('name', $deptName)->first();
            return $dept ? $dept->id : null;
        }
        return null;
    }

    public function approveQuote(ProjectEnquiry $enquiry, int $userId): bool
    {
        return $enquiry->approveQuote($userId);
    }

    public function getEnquiriesForUser($user)
    {
        return $this->enquiryRepository->getByUser($user);
    }

    public function searchEnquiries(string $query, $user = null)
    {
        return $this->enquiryRepository->search($query, $user);
    }

    private function generateEnquiryNumber(): string
    {
        $count = ProjectEnquiry::count() + 1;
        return \App\Constants\EnquiryConstants::ENQUIRY_PREFIX . '-' . date('m') . '-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    private function startWorkflowForEnquiry(ProjectEnquiry $enquiry): void
    {
        $template = $this->workflowRepository->getActiveTemplates('enquiry')->first();

        if ($template) {
            $instance = $this->workflowRepository->createInstance([
                'workflow_template_id' => $template->id,
                'entity_type' => 'enquiry',
                'entity_id' => $enquiry->id,
                'started_at' => now(),
            ]);

            // Create tasks from template
            foreach ($template->templateTasks as $templateTask) {
                $this->workflowRepository->createTask([
                    'workflow_instance_id' => $instance->id,
                    'workflow_template_task_id' => $templateTask->id,
                    'due_date' => $templateTask->estimated_duration_days
                        ? now()->addDays($templateTask->estimated_duration_days)
                        : null,
                ]);
            }
        }
    }
}
