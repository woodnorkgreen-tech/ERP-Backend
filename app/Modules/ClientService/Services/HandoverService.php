<?php

namespace App\Modules\ClientService\Services;

use App\Models\HandoverSurvey;
use App\Models\ProjectEnquiry;
use App\Constants\EnquiryConstants;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HandoverService
{
    /**
     * Get all submitted handover surveys with related project/client data
     */
    public function getHandovers(array $filters = []): array
    {
        $query = HandoverSurvey::with(['task.enquiry.client', 'reviewer'])
            ->where('submitted', true);

        // Filter by client
        if (!empty($filters['client_id'])) {
            $query->whereHas('task.enquiry', function ($q) use ($filters) {
                $q->where('client_id', $filters['client_id']);
            });
        }

        // Search project title, client name, job number, or respondent info
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('task.enquiry.client', function ($qc) use ($search) {
                    $qc->where('first_name', 'like', "%{$search}%")
                       ->orWhere('last_name', 'like', "%{$search}%")
                       ->orWhere('company_name', 'like', "%{$search}%");
                })
                ->orWhereHas('task.enquiry', function ($qe) use ($search) {
                    $qe->where('title', 'like', "%{$search}%")
                       ->orWhere('job_number', 'like', "%{$search}%");
                })
                ->orWhere('respondent_info', 'like', "%{$search}%");
            });
        }

        // Filter by feedback source channel
        if (!empty($filters['feedback_source'])) {
            $query->where('feedback_source', $filters['feedback_source']);
        }

        // Filter by CS review status
        if (!empty($filters['review_status'])) {
            $query->where('review_status', $filters['review_status']);
        }

        $handovers = $query->get();

        // Format and map items first
        $formatted = $handovers->map(function ($h) {
            return $this->formatHandover($h);
        });

        // Apply rating class filter (Promoters: >=4.5, Passives: 3.0-4.4, Detractors: <3.0)
        if (!empty($filters['rating_class'])) {
            $formatted = $formatted->filter(function ($h) use ($filters) {
                $rating = $h['average_rating'];
                if ($filters['rating_class'] === 'promoter') return $rating >= 4.5;
                if ($filters['rating_class'] === 'passive') return $rating >= 3.0 && $rating < 4.5;
                if ($filters['rating_class'] === 'detractor') return $rating < 3.0;
                return true;
            });
        }

        // Apply timeliness filter (on_time vs delayed)
        if (!empty($filters['timeliness'])) {
            $formatted = $formatted->filter(function ($h) use ($filters) {
                // Find actual survey responses
                $survey = HandoverSurvey::find($h['id']);
                if (!$survey) return false;
                $onTime = data_get($survey->responses, 'delivered_on_time');
                $isOnTime = ($onTime === true || $onTime === 'yes' || $onTime === 1 || $onTime === '1' || $onTime === 'true');
                return $filters['timeliness'] === 'on_time' ? $isOnTime : !$isOnTime;
            });
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'newest';
        if ($sortBy === 'newest') {
            $formatted = $formatted->sortByDesc('submitted_at');
        } elseif ($sortBy === 'oldest') {
            $formatted = $formatted->sortBy('submitted_at');
        } elseif ($sortBy === 'highest_rating') {
            $formatted = $formatted->sortByDesc('average_rating');
        } elseif ($sortBy === 'lowest_rating') {
            $formatted = $formatted->sortBy('average_rating');
        }

        // Manual collection pagination
        $page = (int) ($filters['page'] ?? 1);
        $perPage = 15;
        $totalItems = $formatted->count();
        $totalPages = (int) ceil($totalItems / $perPage);
        $pageData = $formatted->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'data' => $pageData->toArray(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $totalPages ?: 1,
                'total' => $totalItems,
            ]
        ];
    }

    /**
     * Get completed projects that have NOT yet returned feedback, so the CS Lead
     * knows who to follow up with. A project counts as "pending feedback" when it
     * is completed but has no submitted handover survey on any of its tasks.
     *
     * Read-only: this does not mint sign-off tokens. Use the existing
     * HandoverSurveyController@generateToken(taskId) endpoint to create/copy a link.
     */
    public function getPendingFeedback(array $filters = []): array
    {
        $query = ProjectEnquiry::with([
                'client',
                'enquiryTasks' => fn ($q) => $q->where('type', 'handover')->with('handoverSurvey'),
            ])
            ->where('status', EnquiryConstants::STATUS_COMPLETED)
            ->whereDoesntHave('enquiryTasks.handoverSurvey', function ($q) {
                $q->where('submitted', true);
            });

        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('job_number', 'like', "%{$search}%")
                  ->orWhereHas('client', function ($qc) use ($search) {
                      $qc->where('full_name', 'like', "%{$search}%")
                         ->orWhere('company_name', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderByDesc('updated_at');

        $page = (int) ($filters['page'] ?? 1);
        $paginator = $query->paginate(15, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(function ($e) {
            $handoverTask = $e->enquiryTasks->first();
            return [
                'enquiry_id'       => $e->id,
                'client_id'        => $e->client_id,
                'client_name'      => $e->client->full_name ?? 'N/A',
                'project_title'    => $e->title ?? 'N/A',
                'job_number'       => $e->job_number ?? 'N/A',
                // updated_at is used as a completion proxy (no dedicated completed_at column)
                'completed_at'     => $e->updated_at ? $e->updated_at->toISOString() : null,
                'handover_task_id' => $handoverTask?->id,
                'access_token'     => $handoverTask?->handoverSurvey?->access_token,
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Submitted surveys not yet reviewed by CS Lead (review_status = pending).
     * Displayed in the "Awaiting CS Review" panel.
     */
    public function getAwaitingReview(array $filters = []): array
    {
        $query = HandoverSurvey::with(['task.enquiry.client'])
            ->where('submitted', true)
            ->where('review_status', 'pending');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('task.enquiry.client', function ($qc) use ($search) {
                    $qc->where('first_name', 'like', "%{$search}%")
                       ->orWhere('last_name', 'like', "%{$search}%")
                       ->orWhere('company_name', 'like', "%{$search}%");
                })->orWhereHas('task.enquiry', function ($qe) use ($search) {
                    $qe->where('title', 'like', "%{$search}%")
                       ->orWhere('job_number', 'like', "%{$search}%");
                });
            });
        }

        $query->orderByDesc('submitted_at');
        $page      = (int) ($filters['page'] ?? 1);
        $paginator = $query->paginate(15, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(function (HandoverSurvey $h) {
            $enquiry = $h->task?->enquiry;
            $client  = $enquiry?->client;
            return [
                'id'             => $h->id,
                'submitted_at'   => $h->submitted_at?->toISOString(),
                'average_rating' => $h->calculateAverageRating(),
                'client_name'    => $client?->full_name ?? 'N/A',
                'project_title'  => $enquiry?->title ?? 'N/A',
                'job_number'     => $enquiry?->job_number ?? 'N/A',
                'respondent'     => $h->respondent_info['name'] ?? 'N/A',
            ];
        })->values()->toArray();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    /**
     * Get detailed handover survey by ID
     */
    public function getHandoverDetails(int $id): ?array
    {
        $handover = HandoverSurvey::with(['task.enquiry.client', 'reviewer', 'ncrReport'])->find($id);

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

        $onTime = data_get($h->responses, 'delivered_on_time');
        $isOnTime = ($onTime === true || $onTime === 'yes' || $onTime === 1 || $onTime === '1' || $onTime === 'true');

        $data = [
            'id'              => $h->id,
            'submitted_at'    => $h->submitted_at ? $h->submitted_at->toISOString() : null,
            'average_rating'  => $h->calculateAverageRating(),
            'client_name'     => $client->full_name ?? 'N/A',
            'project_title'   => $enquiry->title ?? 'N/A',
            'job_number'      => $enquiry->job_number ?? 'N/A',
            'respondent'      => $h->respondent_info['name'] ?? 'N/A',
            'feedback_source' => $h->feedback_source ?? 'survey_link',
            'delivered_on_time' => $isOnTime,
            // CS Lead review state (always included so the list can colour-code)
            'review_status'   => $h->review_status ?? 'pending',
        ];

        if ($detailed) {
            $data['responses']        = $h->responses;
            $data['question_config']  = $h->question_config_snapshot ?? config('survey_questions');
            $data['respondent_info']  = $h->respondent_info;
            $data['evidence_notes']   = $h->evidence_notes;
            // PM's internal notes (from the handover EnquiryTask)
            $data['pm_notes']         = $h->task?->notes;
            // Review detail
            $data['review_notes']     = $h->review_notes;
            $data['reviewed_by_name'] = $h->reviewer?->name;
            $data['reviewed_at']      = $h->reviewed_at?->toISOString();
            // Attached NCR if one was raised
            $data['ncr'] = $h->ncrReport ? [
                'id'                 => $h->ncrReport->id,
                'title'              => $h->ncrReport->title,
                'category'           => $h->ncrReport->category,
                'status'             => $h->ncrReport->status,
                'assigned_department'=> $h->ncrReport->assigned_department,
                'description'        => $h->ncrReport->description,
                'root_cause'         => $h->ncrReport->root_cause,
                'corrective_action'  => $h->ncrReport->corrective_action,
                'resolved_at'        => $h->ncrReport->resolved_at?->toISOString(),
            ] : null;
        }

        return $data;
    }

    /**
     * Get aggregated statistics for all submitted surveys.
     */
    public function getHandoverStats(): array
    {
        $surveys = HandoverSurvey::where('submitted', true)->get();
        $total = $surveys->count();

        if ($total === 0) {
            return [
                'total_count' => 0,
                'average_satisfaction' => 0.0,
                'on_time_delivery_rate' => 0.0,
                'rating_distribution' => [
                    'promoters' => 0,
                    'passives' => 0,
                    'detractors' => 0,
                ],
                'category_averages' => [],
            ];
        }

        $sumSatisfaction = 0.0;
        $onTimeCount = 0;
        $promoters = 0;
        $passives = 0;
        $detractors = 0;

        $categoryScores = [];
        $categoryCounts = [];

        // Build question map for categories
        $questionsMap = [];
        $config = config('survey_questions');
        foreach ($config['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $questionsMap[$question['id']] = [
                    'label' => $question['label'],
                    'section' => $section['title'],
                    'type' => $question['type']
                ];
            }
        }

        foreach ($surveys as $survey) {
            $avgRating = $survey->calculateAverageRating();
            $sumSatisfaction += $avgRating;

            // Classify ratings
            if ($avgRating >= 4.5) {
                $promoters++;
            } elseif ($avgRating >= 3.0) {
                $passives++;
            } else {
                $detractors++;
            }

            // Check on-time delivery
            $onTime = data_get($survey->responses, 'delivered_on_time');
            if ($onTime === true || $onTime === 'yes' || $onTime === 1 || $onTime === '1' || $onTime === 'true') {
                $onTimeCount++;
            }

            // Category scores accumulation
            if ($survey->responses) {
                foreach ($survey->responses as $qId => $val) {
                    $ratingValue = null;
                    if (is_array($val)) {
                        $ratingValue = $val['rating'] ?? null;
                    } elseif (is_numeric($val)) {
                        $ratingValue = $val;
                    }

                    if ($ratingValue !== null && is_numeric($ratingValue)) {
                        if (!isset($categoryScores[$qId])) {
                            $categoryScores[$qId] = 0;
                            $categoryCounts[$qId] = 0;
                        }
                        $categoryScores[$qId] += (float) $ratingValue;
                        $categoryCounts[$qId]++;
                    }
                }
            }
        }

        // Build category breakdown
        $categoryBreakdown = [];
        foreach ($categoryScores as $qId => $sum) {
            $count = $categoryCounts[$qId];
            $avg = $count > 0 ? round($sum / $count, 2) : 0.0;

            if (isset($questionsMap[$qId]) && $questionsMap[$qId]['type'] === 'rating') {
                $categoryBreakdown[] = [
                    'id' => $qId,
                    'label' => $questionsMap[$qId]['label'],
                    'section' => $questionsMap[$qId]['section'],
                    'average' => $avg,
                ];
            }
        }

        return [
            'total_count' => $total,
            'average_satisfaction' => round($sumSatisfaction / $total, 2),
            'on_time_delivery_rate' => round(($onTimeCount / $total) * 100, 1),
            'rating_distribution' => [
                'promoters' => $promoters,
                'passives' => $passives,
                'detractors' => $detractors,
            ],
            'category_averages' => $categoryBreakdown,
        ];
    }
}
