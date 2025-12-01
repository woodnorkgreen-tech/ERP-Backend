<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\HandoverSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublicHandoverController extends Controller
{
    /**
     * Get survey data by access token (for public client access)
     *
     * @param string $token
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($token)
    {
        try {
            $survey = HandoverSurvey::where('access_token', $token)
                ->with(['task.enquiry.client'])
                ->first();

            if (!$survey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired survey link'
                ], 404);
            }

            // Get question configuration
            $questionConfig = config('survey_questions');

            // Format response for public view
            $data = [
                'id' => $survey->id,
                'task_id' => $survey->task_id,
                'project_title' => $survey->task->enquiry->title ?? 'Untitled Project',
                'client_name' => $survey->task->enquiry->client->full_name ?? $survey->task->enquiry->contact_person ?? 'Valued Client',
                'respondent_info' => $survey->respondent_info,
                'responses' => $survey->responses,
                'submitted' => $survey->submitted,
                'submitted_at' => $survey->submitted_at,
                'questions' => $questionConfig, // Send question structure to frontend
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Public survey fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load survey',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Submit survey data by access token (for public client submission)
     *
     * @param string $token
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($token, Request $request)
    {
        try {
            $survey = HandoverSurvey::where('access_token', $token)->first();

            if (!$survey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired survey link'
                ], 404);
            }

            if ($survey->submitted) {
                return response()->json([
                    'success' => false,
                    'message' => 'This survey has already been submitted'
                ], 400);
            }

            // Build dynamic validation rules from config
            $validationRules = $this->buildPublicValidationRules();
            
            $validator = Validator::make($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update survey with submitted data
            $survey->update([
                'respondent_info' => $request->respondent_info,
                'responses' => $request->responses,
                'submitted' => true,
                'submitted_at' => now(),
                'question_config_snapshot' => config('survey_questions'), // Save version used
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Survey submitted successfully',
                'data' => $survey
            ]);

        } catch (\Exception $e) {
            \Log::error('Public survey submission error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit survey',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Build validation rules for public survey submission
     */
    private function buildPublicValidationRules(): array
    {
        $config = config('survey_questions');
        $rules = [
            'respondent_info' => 'nullable|string|max:500',
            'responses' => 'required|array',
        ];

        // Build rules for each question
        foreach ($config['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $fieldPath = "responses.{$question['id']}";
                $required = ($question['required'] ?? false) ? 'required' : 'nullable';

                switch ($question['type']) {
                    case 'rating':
                        if ($question['has_remarks'] ?? false) {
                            $rules["{$fieldPath}.rating"] = "{$required}|integer|min:1|max:5";
                            $rules["{$fieldPath}.remarks"] = "nullable|string|max:1000";
                        } else {
                            $rules[$fieldPath] = "{$required}|integer|min:1|max:5";
                        }
                        break;

                    case 'yes_no':
                        $rules[$fieldPath] = "{$required}|boolean";
                        break;

                    case 'text':
                        $rules[$fieldPath] = "{$required}|string|max:255";
                        break;

                    case 'textarea':
                        $rules[$fieldPath] = "{$required}|string|max:2000";
                        break;
                }
            }
        }

        return $rules;
    }
}
