<?php

namespace App\Http\Controllers;

use App\Models\SiteSurvey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SiteSurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info("[DEBUG] SiteSurveyController::index called with params: " . json_encode($request->all()));

        $query = SiteSurvey::with('enquiry.client', 'enquiryTask');

        if ($request->has('enquiry_task_id')) {
            $enquiryTaskId = $request->enquiry_task_id;
            \Log::info("[DEBUG] Filtering by enquiry_task_id: {$enquiryTaskId}");
            $query->where('enquiry_task_id', $enquiryTaskId);
        }

        if ($request->has('project_enquiry_id')) {
            $projectEnquiryId = $request->project_enquiry_id;
            \Log::info("[DEBUG] Filtering by project_enquiry_id: {$projectEnquiryId}");
            $query->where('project_enquiry_id', $projectEnquiryId);
        }

        if ($request->has('project_id')) {
            $projectId = $request->project_id;
            \Log::info("[DEBUG] Filtering by project_id: {$projectId}");
            $query->where('project_id', $projectId);
        }

        $siteSurveys = $query->get();
        \Log::info("[DEBUG] SiteSurveyController::index returning " . $siteSurveys->count() . " site surveys");

        return response()->json($siteSurveys);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_enquiry_id' => 'required|numeric|exists:project_enquiries,id',
            'enquiry_task_id' => 'nullable|numeric|exists:enquiry_tasks,id',
            'project_id' => 'nullable|numeric',
            'site_visit_date' => 'required|date',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'approved', 'rejected'])],
            'project_manager' => 'nullable|string|max:255',
            'other_project_manager' => 'nullable|string|max:255',
            'client_name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'attendees' => 'nullable|array',
            'attendees.*' => 'string',
            'client_contact_person' => 'nullable|string|max:255',
            'client_phone' => 'nullable|string|max:20',
            'client_email' => 'nullable|email|max:255',
            'project_description' => 'required|string',
            'objectives' => 'nullable|string',
            'current_condition' => 'nullable|string',
            'existing_branding' => 'nullable|string',
            'access_logistics' => 'nullable|string',
            'parking_availability' => 'nullable|string',
            'size_accessibility' => 'nullable|string',
            'lifts' => 'nullable|string|max:255',
            'door_sizes' => 'nullable|string|max:255',
            'loading_areas' => 'nullable|string',
            'site_measurements' => 'nullable|string',
            'room_size' => 'nullable|string|max:255',
            'constraints' => 'nullable|string',
            'electrical_outlets' => 'nullable|string',
            'food_refreshment' => 'nullable|string',
            'branding_preferences' => 'nullable|string',
            'material_preferences' => 'nullable|string',
            'color_scheme' => 'nullable|string|max:255',
            'brand_guidelines' => 'nullable|string',
            'special_instructions' => 'nullable|string',
            'project_start_date' => 'nullable|date',
            'project_deadline' => 'nullable|date',
            'milestones' => 'nullable|string',
            'safety_conditions' => 'nullable|string',
            'potential_hazards' => 'nullable|string',
            'safety_requirements' => 'nullable|string',
            'additional_notes' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'action_items' => 'nullable|array',
            'action_items.*' => 'string',
            'prepared_by' => 'nullable|string|max:255',
            'prepared_signature' => 'nullable|string',
            'prepared_date' => 'nullable|date',
            'client_approval' => 'nullable|boolean',
            'client_signature' => 'nullable|string',
            'client_approval_date' => 'nullable|date',
        ]);

        // If enquiry_task_id is not provided, automatically find and set the survey task
        if (!isset($validated['enquiry_task_id']) || !$validated['enquiry_task_id']) {
            $surveyTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $validated['project_enquiry_id'])
                ->where('type', 'site-survey')
                ->first();

            if ($surveyTask) {
                $validated['enquiry_task_id'] = $surveyTask->id;
                \Log::info("[DEBUG] SiteSurveyController::store automatically linked to survey task ID: {$surveyTask->id}");
            } else {
                \Log::warning("[DEBUG] SiteSurveyController::store no survey task found for enquiry ID: {$validated['project_enquiry_id']}");
            }
        }

        $siteSurvey = SiteSurvey::create($validated);

        return response()->json($siteSurvey->load('enquiry.client', 'enquiryTask'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SiteSurvey $siteSurvey): JsonResponse
    {
        return response()->json($siteSurvey->load('enquiry.client', 'enquiryTask'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SiteSurvey $siteSurvey): JsonResponse
    {
        $validated = $request->validate([
            'project_enquiry_id' => 'sometimes|numeric|exists:project_enquiries,id',
            'enquiry_task_id' => 'nullable|numeric|exists:enquiry_tasks,id',
            'project_id' => 'nullable|numeric',
            'site_visit_date' => 'sometimes|date',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'approved', 'rejected'])],
            'project_manager' => 'nullable|string|max:255',
            'other_project_manager' => 'nullable|string|max:255',
            'client_name' => 'sometimes|string|max:255',
            'location' => 'sometimes|string|max:255',
            'attendees' => 'nullable|array',
            'attendees.*' => 'string',
            'client_contact_person' => 'nullable|string|max:255',
            'client_phone' => 'nullable|string|max:20',
            'client_email' => 'nullable|email|max:255',
            'project_description' => 'sometimes|string',
            'objectives' => 'nullable|string',
            'current_condition' => 'nullable|string',
            'existing_branding' => 'nullable|string',
            'access_logistics' => 'nullable|string',
            'parking_availability' => 'nullable|string',
            'size_accessibility' => 'nullable|string',
            'lifts' => 'nullable|string|max:255',
            'door_sizes' => 'nullable|string|max:255',
            'loading_areas' => 'nullable|string',
            'site_measurements' => 'nullable|string',
            'room_size' => 'nullable|string|max:255',
            'constraints' => 'nullable|string',
            'electrical_outlets' => 'nullable|string',
            'food_refreshment' => 'nullable|string',
            'branding_preferences' => 'nullable|string',
            'material_preferences' => 'nullable|string',
            'color_scheme' => 'nullable|string|max:255',
            'brand_guidelines' => 'nullable|string',
            'special_instructions' => 'nullable|string',
            'project_start_date' => 'nullable|date',
            'project_deadline' => 'nullable|date',
            'milestones' => 'nullable|string',
            'safety_conditions' => 'nullable|string',
            'potential_hazards' => 'nullable|string',
            'safety_requirements' => 'nullable|string',
            'additional_notes' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'action_items' => 'nullable|array',
            'action_items.*' => 'string',
            'prepared_by' => 'nullable|string|max:255',
            'prepared_signature' => 'nullable|string',
            'prepared_date' => 'nullable|date',
            'client_approval' => 'nullable|boolean',
            'client_signature' => 'nullable|string',
            'client_approval_date' => 'nullable|date',
        ]);

        // If enquiry_task_id is not provided, automatically find and set the survey task
        if (!isset($validated['enquiry_task_id']) || !$validated['enquiry_task_id']) {
            $surveyTask = \App\Modules\Projects\Models\EnquiryTask::where('project_enquiry_id', $validated['project_enquiry_id'])
                ->where('type', 'site-survey')
                ->first();

            if ($surveyTask) {
                $validated['enquiry_task_id'] = $surveyTask->id;
                \Log::info("[DEBUG] SiteSurveyController::update automatically linked to survey task ID: {$surveyTask->id}");
            } else {
                \Log::warning("[DEBUG] SiteSurveyController::update no survey task found for enquiry ID: {$validated['project_enquiry_id']}");
            }
        }

        $siteSurvey->update($validated);

        return response()->json($siteSurvey->load('enquiry.client', 'enquiryTask'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SiteSurvey $siteSurvey): JsonResponse
    {
        $siteSurvey->delete();

        return response()->json(['message' => 'Site survey deleted successfully']);
    }

    /**
     * Generate PDF for the specified site survey.
     */
    public function generatePDF(SiteSurvey $siteSurvey)
    {
        $siteSurvey->load('enquiry.client', 'enquiryTask');

        $pdf = \PDF::loadView('pdf.site-survey', compact('siteSurvey'));

        $filename = 'site-survey-' . $siteSurvey->id . '-' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Upload a photo for the survey
     */
    public function uploadPhoto(Request $request, int $taskId): JsonResponse
    {
        try {
            $request->validate([
                'photo' => 'required|image|mimes:jpeg,jpg,png|max:10240', // Max 10MB
                'caption' => 'nullable|string|max:255'
            ]);

            // Find or create survey by task ID
            $task = \App\Modules\Projects\Models\EnquiryTask::findOrFail($taskId);
            
            $survey = SiteSurvey::firstOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_enquiry_id' => $task->project_enquiry_id,
                    'site_visit_date' => now()->format('Y-m-d'),
                    'client_name' => 'To be filled',
                    'location' => 'To be filled',
                    'project_description' => 'To be filled',
                    'status' => 'pending'
                ]
            );

            // Get existing photos
            $photos = $survey->survey_photos ?? [];

            // Limit to 20 photos
            if (count($photos) >= 20) {
                return response()->json([
                    'message' => 'Maximum 20 photos allowed per survey'
                ], 422);
            }

            // Handle file upload
            $file = $request->file('photo');
            $uuid = \Illuminate\Support\Str::uuid();
            $extension = $file->getClientOriginalExtension();
            $filename = $uuid . '.' . $extension;
            
            // Create directory if it doesn't exist
            $directory = 'surveys/task_' . $taskId;
            $path = $file->storeAs($directory, $filename, 'public');

            // Add photo metadata
            $photoData = [
                'id' => (string) $uuid,
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => '/system/storage/' . $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_at' => now()->toISOString(),
                'caption' => $request->input('caption', '')
            ];

            $photos[] = $photoData;
            $survey->survey_photos = $photos;
            $survey->save();

            return response()->json([
                'message' => 'Photo uploaded successfully',
                'photo' => $photoData
            ]);
        } catch (\Exception $e) {
            \Log::error('Survey photo upload failed', [
                'taskId' => $taskId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to upload photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a photo from the survey
     */
    public function deletePhoto(int $taskId, string $photoId): JsonResponse
    {
        try {
            // Find survey by task ID
            $survey = SiteSurvey::where('enquiry_task_id', $taskId)->firstOrFail();

            // Get existing photos
            $photos = $survey->survey_photos ?? [];

            // Find and remove the photo
            $photoIndex = null;
            $photoToDelete = null;

            foreach ($photos as $index => $photo) {
                if ($photo['id'] === $photoId) {
                    $photoIndex = $index;
                    $photoToDelete = $photo;
                    break;
                }
            }

            if ($photoIndex === null) {
                return response()->json([
                    'message' => 'Photo not found'
                ], 404);
            }

            // Delete file from storage
            if (isset($photoToDelete['path'])) {
                \Storage::disk('public')->delete($photoToDelete['path']);
            }

            // Remove from array
            array_splice($photos, $photoIndex, 1);
            $survey->survey_photos = $photos;
            $survey->save();

            return response()->json([
                'message' => 'Photo deleted successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Survey photo deletion failed', [
                'taskId' => $taskId,
                'photoId' => $photoId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete photo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

