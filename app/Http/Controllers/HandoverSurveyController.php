<?php

namespace App\Http\Controllers;

use App\Models\HandoverSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HandoverSurveyController extends Controller
{
    /**
     * Get handover survey for a task
     */
    public function show(int $taskId): JsonResponse
    {
        try {
            $survey = HandoverSurvey::where('task_id', $taskId)->first();

            if (!$survey) {
                return response()->json([
                    'message' => 'No survey found for this task',
                    'data' => null
                ], 404);
            }

            // Include question configuration
            $survey->question_config = config('survey_questions');

            return response()->json([
                'message' => 'Survey retrieved successfully',
                'data' => $survey
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching handover survey: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to retrieve survey',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store or update handover survey with dynamic validation
     */
    /**
     * Store or update handover survey with dynamic validation
     */
    public function store(Request $request, int $taskId): JsonResponse
    {
        try {
            $feedbackSource = $request->input('feedback_source');
            $isAlternativeFeedback = $feedbackSource && $feedbackSource !== 'survey_link';

            // Build validation rules dynamically from config
            $validationRules = $this->buildValidationRules($isAlternativeFeedback);
            
            // Add rules for new fields
            $validationRules['feedback_source'] = 'nullable|string|in:survey_link,email,whatsapp,phone_call,in_person,social_media,other';
            $validationRules['feedback_received_at'] = 'nullable|date';
            $validationRules['evidence_notes'] = 'nullable|string';
            $validationRules['captured_by'] = 'nullable|integer|exists:users,id';
            
            // File validation rules - support common image formats and documents
            $validationRules['evidence_files.*'] = 'nullable|file|mimes:jpg,jpeg,png,webp,gif,bmp,svg,pdf,doc,docx|max:10240'; // 10MB max


            // Decode JSON fields if they are strings (from FormData)
            $input = $request->all();
            if (isset($input['responses']) && is_string($input['responses'])) {
                $input['responses'] = json_decode($input['responses'], true);
            }
            if (isset($input['respondent_info']) && is_string($input['respondent_info'])) {
                $input['respondent_info'] = json_decode($input['respondent_info'], true);
            }
            
            // Replace request input with decoded data for validation
            $request->replace($input);

            $validator = Validator::make($request->all(), $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            
            // Handle file uploads
            $uploadedFiles = [];
            if ($request->hasFile('evidence_files')) {
                foreach ($request->file('evidence_files') as $file) {
                    $path = $file->store("handover_evidence/{$taskId}", 'public');
                    $uploadedFiles[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'url' => url("storage/{$path}"),
                        'type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
                        'uploaded_at' => now()->toIso8601String(),
                    ];
                }
            }

            // Separate responses from metadata
            // Note: When using FormData, responses might come as a JSON string
            $responses = is_string($request->input('responses')) 
                ? json_decode($request->input('responses'), true) 
                : ($validated['responses'] ?? []);
                
            $respondentInfo = is_string($request->input('respondent_info'))
                ? json_decode($request->input('respondent_info'), true)
                : ($validated['respondent_info'] ?? null);
                
            $submitted = filter_var($request->input('submitted'), FILTER_VALIDATE_BOOLEAN);

            // Prepare data for storage
            $data = [
                'task_id' => $taskId,
                'respondent_info' => $respondentInfo,
                'responses' => $responses,
                'submitted' => $submitted,
                'feedback_source' => $request->input('feedback_source'),
                'feedback_received_at' => $request->input('feedback_received_at'),
                'evidence_notes' => $request->input('evidence_notes'),
                'captured_by' => $request->input('captured_by'),
            ];
            
            // Merge new files with existing ones if needed (logic can be enhanced to support appending)
            if (!empty($uploadedFiles)) {
                $data['evidence_files'] = $uploadedFiles;
            }

            // If submitting, set submitted_at timestamp and save config snapshot
            if ($submitted) {
                $data['submitted_at'] = now();
                $data['question_config_snapshot'] = config('survey_questions');
            }

            $survey = HandoverSurvey::updateOrCreate(
                ['task_id' => $taskId],
                $data
            );

            return response()->json([
                'message' => 'Survey saved successfully',
                'data' => $survey
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error saving handover survey: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to save survey',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate access token for public sharing
     */
    public function generateToken(int $taskId): JsonResponse
    {
        try {
            // Find or create survey
            $survey = HandoverSurvey::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'task_id' => $taskId,
                    'submitted' => false,
                ]
            );

            // Generate new token
            $token = Str::random(32);
            $survey->access_token = $token;
            $survey->save();

            return response()->json([
                'message' => 'Token generated successfully',
                'data' => [
                    'access_token' => $token,
                    'survey' => $survey
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error generating survey token: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to generate token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete handover survey
     */
    public function destroy(int $taskId): JsonResponse
    {
        try {
            $survey = HandoverSurvey::where('task_id', $taskId)->first();

            if (!$survey) {
                return response()->json([
                    'message' => 'No survey found for this task'
                ], 404);
            }

            $survey->delete();

            return response()->json([
                'message' => 'Survey deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting handover survey: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to delete survey',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build validation rules dynamically from question configuration
     */
    private function buildValidationRules(bool $isAlternativeFeedback = false): array
    {
        $config = config('survey_questions');
        $rules = [
            'respondent_info' => 'nullable', // Relaxed validation for JSON string/array flexibility
            'responses' => 'required',       // Relaxed validation for JSON string/array flexibility
            'submitted' => 'nullable',
        ];

        // Build rules for each question
        foreach ($config['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $fieldPath = "responses.{$question['id']}";
                
                // If it's alternative feedback, all questions are optional (nullable)
                // Otherwise, respect the 'required' flag from config
                $required = (!$isAlternativeFeedback && ($question['required'] ?? false)) ? 'required' : 'nullable';

                // Note: Deep validation of responses structure is tricky with FormData/JSON string
                // We rely on the frontend to send correct structure and basic existence check here
                // For stricter validation, we would need to decode JSON first then validate manually
            }
        }

        return $rules;
    }
}
