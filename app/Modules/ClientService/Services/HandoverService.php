<?php

namespace App\Modules\ClientService\Services;

use App\Models\HandoverSurvey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HandoverService
{
    /**
     * Get all submitted handover surveys with related project/client data
     */
    public function getHandovers(array $filters = []): array
    {
        $query = HandoverSurvey::with(['task.enquiry.client'])
            ->where('submitted', true)
            ->orderBy('submitted_at', 'desc');

        // Apply filters if needed (e.g., client_id, project_title)
        if (!empty($filters['client_id'])) {
            $query->whereHas('task.enquiry', function ($q) use ($filters) {
                $q->where('client_id', $filters['client_id']);
            });
        }

        $handovers = $query->paginate(15);

        return [
            'data' => $handovers->map(function ($h) {
                return $this->formatHandover($h);
            }),
            'meta' => [
                'current_page' => $handovers->currentPage(),
                'last_page' => $handovers->lastPage(),
                'total' => $handovers->total(),
            ]
        ];
    }

    /**
     * Get detailed handover survey by ID
     */
    public function getHandoverDetails(int $id): ?array
    {
        $handover = HandoverSurvey::with(['task.enquiry.client'])->find($id);
        
        if (!$handover) return null;

        return $this->formatHandover($handover, true);
    }

    /**
     * Format handover data for frontend
     */
    private function formatHandover(HandoverSurvey $h, bool $detailed = false): array
    {
        $enquiry = $h->task->enquiry ?? null;
        $client = $enquiry->client ?? null;

        $data = [
            'id' => $h->id,
            'submitted_at' => $h->submitted_at ? $h->submitted_at->toISOString() : null,
            'average_rating' => $h->calculateAverageRating(),
            'client_name' => $client->full_name ?? 'N/A',
            'project_title' => $enquiry->title ?? 'N/A',
            'job_number' => $enquiry->job_number ?? 'N/A',
            'respondent' => $h->respondent_info['name'] ?? 'N/A',
            'feedback_source' => $h->feedback_source ?? 'survey_link',
        ];

        if ($detailed) {
            $data['responses'] = $h->responses;
            $data['question_config'] = $h->question_config_snapshot ?? config('survey_questions');
            $data['respondent_info'] = $h->respondent_info;
            $data['evidence_notes'] = $h->evidence_notes;
        }

        return $data;
    }
}
