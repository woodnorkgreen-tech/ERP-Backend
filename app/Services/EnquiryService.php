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
        return $enquiry->fresh();
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
        return 'ENQ-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
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
