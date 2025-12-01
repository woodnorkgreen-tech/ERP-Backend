<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HandoverSurvey extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'access_token',
        'respondent_info',
        'responses',
        'question_config_snapshot',
        'submitted',
        'submitted_at',
        'feedback_source',
        'feedback_received_at',
        'evidence_notes',
        'evidence_files',
        'captured_by',
    ];

    protected $casts = [
        'submitted' => 'boolean',
        'submitted_at' => 'datetime',
        'feedback_received_at' => 'datetime',
        'responses' => 'array',
        'respondent_info' => 'array',
        'question_config_snapshot' => 'array',
        'evidence_files' => 'array',
    ];

    /**
     * Get the task that owns this survey
     */
    public function task()
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'task_id');
    }

    /**
     * Get a specific response by question ID
     */
    public function getResponse(string $questionId, $default = null)
    {
        return data_get($this->responses, $questionId, $default);
    }

    /**
     * Set a specific response
     */
    public function setResponse(string $questionId, $value): void
    {
        $responses = $this->responses ?? [];
        data_set($responses, $questionId, $value);
        $this->responses = $responses;
    }

    /**
     * Calculate average rating from all rating-type questions
     */
    public function calculateAverageRating(): float
    {
        if (!$this->responses) {
            return 0.0;
        }

        $ratings = [];
        
        // Extract all rating values from responses
        foreach ($this->responses as $questionId => $response) {
            // If response has a 'rating' key (for questions with remarks)
            if (is_array($response) && isset($response['rating']) && is_numeric($response['rating'])) {
                $ratings[] = (float) $response['rating'];
            }
            // If response is directly a numeric rating
            elseif (is_numeric($response) && $response >= 1 && $response <= 5) {
                $ratings[] = (float) $response;
            }
        }

        if (empty($ratings)) {
            return 0.0;
        }

        return round(array_sum($ratings) / count($ratings), 1);
    }

    /**
     * Get all rating questions with their values
     */
    public function getRatingQuestions(): array
    {
        $config = config('survey_questions');
        $ratings = [];

        foreach ($config['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if ($question['type'] === 'rating') {
                    $value = $this->getResponse($question['id']);
                    $ratingValue = null;
                    
                    if (is_array($value)) {
                        $ratingValue = $value['rating'] ?? null;
                    } elseif (is_numeric($value)) {
                        $ratingValue = $value;
                    }
                    
                    $ratings[$question['id']] = [
                        'label' => $question['label'],
                        'value' => $ratingValue,
                        'remarks' => is_array($value) ? ($value['remarks'] ?? null) : null,
                    ];
                }
            }
        }

        return $ratings;
    }
}

