<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;

class ProjectIdentityResolver
{
    public function resolve(array $data): array
    {
        $project = null;
        $enquiry = null;

        if (!empty($data['requisition_id'])) {
            $requisition = PettyCashRequisition::with(['project.enquiry', 'enquiry'])->find($data['requisition_id']);
            if ($requisition) {
                $project = $requisition->project;
                $enquiry = $requisition->enquiry ?? $project?->enquiry;
                $data['project_id'] = $data['project_id'] ?? $requisition->project_id;
                $data['project_enquiry_id'] = $data['project_enquiry_id'] ?? $requisition->enquiry_id ?? $project?->enquiry_id;
            }
        }

        if (!$project && !empty($data['project_id'])) {
            $project = Project::with('enquiry')->find($data['project_id']);
            $enquiry = $project?->enquiry;
            $data['project_enquiry_id'] = $data['project_enquiry_id'] ?? $project?->enquiry_id;
        }

        if (!$enquiry && !empty($data['project_enquiry_id'])) {
            $enquiry = ProjectEnquiry::with('project')->find($data['project_enquiry_id']);
            $project = $project ?? $enquiry?->project;
            $data['project_id'] = $data['project_id'] ?? $project?->id;
        }

        if (!$enquiry && !empty($data['job_number'])) {
            $jobNumber = trim((string) $data['job_number']);
            $enquiry = ProjectEnquiry::with('project')
                ->where('job_number', $jobNumber)
                ->orWhereHas('project', fn ($query) => $query->where('project_id', $jobNumber))
                ->first();
            $project = $project ?? $enquiry?->project;
            $data['project_id'] = $data['project_id'] ?? $project?->id;
            $data['project_enquiry_id'] = $data['project_enquiry_id'] ?? $enquiry?->id;
        }

        if ($enquiry) {
            if (empty($data['job_number'])) {
                $data['job_number'] = $enquiry->job_number ?? $project?->project_id;
            }

            if (empty($data['project_name'])) {
                $data['project_name'] = $enquiry->title;
            }
        }

        return $data;
    }
}
