<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\CandidateDocument;
use App\Modules\HR\Http\Requests\CandidateApplicationRequest;
use App\Modules\HR\Notifications\CandidateRejectedNotification;
use App\Modules\HR\Services\AutoShortlistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RecruitmentController extends Controller
{
    // ==========================================
    // PUBLIC ROUTES (Careers Hub)
    // ==========================================

    public function publicJobs()
    {
        // Only return published jobs
        $jobs = JobPosting::where('status', 'Published')->latest()->get();
        return response()->json($jobs);
    }

    public function publicJobDetails($id)
    {
        $job = JobPosting::where('status', 'Published')->findOrFail($id);
        return response()->json($job);
    }

    public function apply(CandidateApplicationRequest $request)
    {
        $job = JobPosting::findOrFail($request->job_posting_id);

        if (! $job->is_accepting_applications) {
            return response()->json([
                'message' => 'Applications are closed for this posting.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Create Base Candidate Profile
            $candidate = Candidate::create([
                'job_posting_id' => $request->job_posting_id,
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'gender' => $request->gender,
                'dob' => $request->dob,
                'nationality' => $request->nationality,
                'county_of_residence' => $request->county_of_residence,
                'id_type' => $request->id_type,
                'id_number' => $request->id_number,
                'kra_pin' => $request->kra_pin,
                'marital_status' => $request->marital_status,
                'status' => 'New',
                'skills' => config('database.default') === 'sqlite' ? json_encode($request->skills) : $request->skills,
                'software_proficiency' => config('database.default') === 'sqlite' ? json_encode($request->software_proficiency) : $request->software_proficiency,
                'certifications' => config('database.default') === 'sqlite' ? json_encode($request->certifications) : $request->certifications,
                'questionnaire_responses' => config('database.default') === 'sqlite' ? json_encode($request->questionnaire_responses) : $request->questionnaire_responses,
            ]);

            // 2. Insert Experiences
            if ($request->has('experiences') && is_array($request->experiences)) {
                $candidate->experiences()->createMany($request->experiences);
            }

            // 3. Insert Educations
            if ($request->has('educations') && is_array($request->educations)) {
                $candidate->educations()->createMany($request->educations);
            }

            // 4. Insert References
            if ($request->has('references_list') && is_array($request->references_list)) {
                $candidate->references()->createMany($request->references_list);
            }

            // 5. Handle Documents Uploads
            $this->uploadCandidateFile($request, 'cv_file', 'CV', $candidate->id);
            $this->uploadCandidateFile($request, 'cover_letter', 'Cover Letter', $candidate->id);
            $this->uploadCandidateFile($request, 'portfolio', 'Portfolio', $candidate->id);

            DB::commit();

            return response()->json([
                'message' => 'Application submitted successfully!',
                'candidate_id' => $candidate->id
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Application submission failed.', 'message' => $e->getMessage()], 500);
        }
    }

    private function uploadCandidateFile($request, $inputKey, $docType, $candidateId)
    {
        if ($request->hasFile($inputKey)) {
            $file = $request->file($inputKey);
            $path = $file->store('recruitment/documents', 'public');

            CandidateDocument::create([
                'candidate_id' => $candidateId,
                'document_type' => $docType,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
            ]);
        }
    }


    // ==========================================
    // INTERNAL ROUTES (Admin / ATS)
    // ==========================================

    public function adminJobs()
    {
        $jobs = JobPosting::withCount('candidates')->latest()->get();
        return response()->json($jobs);
    }

    public function storeJob(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'location' => 'nullable|string',
            'position_summary' => 'nullable|string',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'education_training' => 'nullable|string',
            'experience' => 'nullable|string',
            'software_tools' => 'nullable|string',
            'skillset' => 'nullable|string',
            'application_deadline' => 'nullable|date',
            'status' => 'required|in:Draft,Published,Closed,On Hold'
        ]);

        $validated['created_by'] = $request->user()->id;
        $job = JobPosting::create($validated);

        return response()->json($job, 201);
    }

    public function updateJob(Request $request, $id)
    {
        $job = JobPosting::findOrFail($id);
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department' => 'nullable|string',
            'employment_type' => 'nullable|string',
            'location' => 'nullable|string',
            'position_summary' => 'nullable|string',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'education_training' => 'nullable|string',
            'experience' => 'nullable|string',
            'software_tools' => 'nullable|string',
            'skillset' => 'nullable|string',
            'application_deadline' => 'nullable|date',
            'status' => 'required|in:Draft,Published,Closed,On Hold'
        ]);

        $job->update($validated);
        return response()->json($job);
    }

    public function repostJob(Request $request, $id)
    {
        $source = JobPosting::findOrFail($id);

        $validated = $request->validate([
            'application_deadline' => 'nullable|date',
            'status' => 'required|in:Draft,Published',
        ]);

        $copyFields = [
            'title',
            'department',
            'employment_type',
            'location',
            'position_summary',
            'description',
            'responsibilities',
            'education_training',
            'experience',
            'software_tools',
            'skillset',
            'requirements',
            'shortlisting_criteria',
            'shortlist_threshold',
        ];

        $payload = collect($source->only($copyFields))
            ->merge([
                'status' => $validated['status'],
                'application_deadline' => $validated['application_deadline'] ?? null,
                'reposted_from_id' => $source->id,
                'created_by' => $request->user()->id,
            ])
            ->all();

        $job = JobPosting::create($payload);

        return response()->json($job, 201);
    }

    public function destroyJob($id)
    {
        JobPosting::findOrFail($id)->delete();
        return response()->json(['message' => 'Job deleted']);
    }

    public function adminCandidates(Request $request)
    {
        $query = Candidate::with('jobPosting');
        
        if ($request->has('job_posting_id')) {
            $query->where('job_posting_id', $request->job_posting_id);
        }

        $candidates = $query->latest()->get();
        return response()->json($candidates);
    }

    public function candidateDetails($id)
    {
        $candidate = Candidate::with(['jobPosting', 'experiences', 'educations', 'references', 'documents'])->findOrFail($id);
        return response()->json($candidate);
    }

    public function updateCandidateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:New,Shortlisted,Interviewing,Background Check,Offered,Hired,Rejected'
        ]);

        $candidate = Candidate::findOrFail($id);

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'Background Check' && empty($candidate->background_check_status)) {
            $updates['background_check_status'] = 'Pending';
            $updates['background_check_completed_at'] = null;
        }

        $candidate->update($updates);

        if ($validated['status'] === 'Rejected' && !empty($candidate->email)) {
            try {
                $candidate->notify(new CandidateRejectedNotification(
                    $candidate->jobPosting?->title ?? 'the position'
                ));
            } catch (\Exception $e) {
                report($e);
            }
        }

        return response()->json($candidate);
    }

    public function downloadDocument($id, $documentId)
    {
        $candidate = Candidate::findOrFail($id);
        $document = $candidate->documents()->findOrFail($documentId);

        $fullPath = Storage::disk('public')->path($document->file_path);

        if (!file_exists($fullPath)) {
            abort(404, 'File not found on disk.');
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        $fileContents = file_get_contents($fullPath);

        return response($fileContents, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
            'Content-Length'      => strlen($fileContents),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    // ==========================================
    // AUTO-SHORTLISTING
    // ==========================================

    /**
     * GET /jobs/{id}/shortlist-criteria
     * Returns saved criteria + threshold + pool of available options from actual candidate data.
     */
    public function getShortlistCriteria($id)
    {
        $job = JobPosting::findOrFail($id);

        // Build available options from what candidates actually submitted
        $candidates = Candidate::with(['experiences', 'educations'])
            ->where('job_posting_id', $id)
            ->whereIn('status', ['New', 'Shortlisted'])
            ->get();

        $allSkills             = collect($this->splitPostingList($job->skillset));
        $allCerts              = collect();
        $allSoftware           = collect($this->splitPostingList($job->software_tools));
        $allQKeys              = collect();
        $allFieldsOfStudy      = collect();
        $allQuestionnaireAnswers = [];

        foreach ($candidates as $c) {
            $allSkills   = $allSkills->merge($c->skills ?? []);
            $allCerts    = $allCerts->merge($c->certifications ?? []);
            $allSoftware = $allSoftware->merge($this->normalizeSoftwareList($c->software_proficiency ?? []));

            foreach ($c->educations as $edu) {
                if (!empty($edu->field_of_study)) {
                    $allFieldsOfStudy->push($edu->field_of_study);
                }
            }

            if (!empty($c->questionnaire_responses)) {
                foreach ((array) $c->questionnaire_responses as $key => $answer) {
                    $allQKeys = $allQKeys->push($key);
                    $allQuestionnaireAnswers[$key] = array_unique(array_merge(
                        $allQuestionnaireAnswers[$key] ?? [],
                        [strval($answer)]
                    ));
                }
            }
        }

        $suggestedCriteria = $this->buildSuggestedShortlistCriteria($job);

        return response()->json([
            'criteria'          => $job->shortlisting_criteria ?? [],
            'suggested_criteria' => $suggestedCriteria,
            'threshold'         => $job->shortlist_threshold ?? 60,
            'available_options' => [
                'skills'              => $allSkills->filter()->unique()->values(),
                'certifications'      => $allCerts->filter()->unique()->values(),
                'software'            => $allSoftware->filter()->unique()->values(),
                'fields_of_study'     => $allFieldsOfStudy->unique()->values(),
                'questionnaire_keys'  => $allQKeys->unique()->values(),
                'questionnaire_answers' => $allQuestionnaireAnswers,
            ],
            'candidate_pool'    => $candidates->count(),
        ]);
    }

    /**
     * PUT /jobs/{id}/shortlist-criteria
     * Save criteria + threshold without triggering.
     */
    public function saveShortlistCriteria(Request $request, $id)
    {
        $job = JobPosting::findOrFail($id);

        $validated = $request->validate([
            'criteria'  => 'required|array',
            'criteria.min_experience_years' => 'sometimes|array',
            'criteria.min_experience_years.enabled' => 'sometimes|boolean',
            'criteria.min_experience_years.value' => 'sometimes|integer|min:0',
            'criteria.min_experience_years.weight' => 'sometimes|integer|min:0',
            'criteria.education_level' => 'sometimes|array',
            'criteria.education_level.enabled' => 'sometimes|boolean',
            'criteria.education_level.value' => 'sometimes|string|in:Certificate,Diploma,Degree,Masters,PhD',
            'criteria.education_level.weight' => 'sometimes|integer|min:0',
            'criteria.field_of_study' => 'sometimes|array',
            'criteria.field_of_study.enabled' => 'sometimes|boolean',
            'criteria.field_of_study.value' => 'sometimes|nullable|string',
            'criteria.field_of_study.weight' => 'sometimes|integer|min:0',
            'criteria.skills' => 'sometimes|array',
            'criteria.skills.enabled' => 'sometimes|boolean',
            'criteria.skills.value' => 'sometimes|array',
            'criteria.skills.value.*' => 'string',
            'criteria.skills.weight' => 'sometimes|integer|min:0',
            'criteria.certifications' => 'sometimes|array',
            'criteria.certifications.enabled' => 'sometimes|boolean',
            'criteria.certifications.value' => 'sometimes|array',
            'criteria.certifications.value.*' => 'string',
            'criteria.certifications.weight' => 'sometimes|integer|min:0',
            'criteria.software' => 'sometimes|array',
            'criteria.software.enabled' => 'sometimes|boolean',
            'criteria.software.value' => 'sometimes|array',
            'criteria.software.value.*' => 'string',
            'criteria.software.weight' => 'sometimes|integer|min:0',
            'criteria.expected_salary_range' => 'sometimes|array',
            'criteria.expected_salary_range.enabled' => 'sometimes|boolean',
            'criteria.expected_salary_range.min' => 'sometimes|nullable|integer|min:0',
            'criteria.expected_salary_range.max' => 'sometimes|nullable|integer|min:0',
            'criteria.questionnaire_rules' => 'sometimes|array',
            'criteria.questionnaire_rules.enabled' => 'sometimes|boolean',
            'criteria.questionnaire_rules.weight' => 'sometimes|integer|min:0',
            'criteria.questionnaire_rules.rules' => 'sometimes|array',
            'criteria.questionnaire_rules.rules.*.key' => 'required_with:criteria.questionnaire_rules.enabled|string',
            'criteria.questionnaire_rules.rules.*.accepted_answers' => 'required_with:criteria.questionnaire_rules.enabled|array',
            'criteria.questionnaire_rules.rules.*.accepted_answers.*' => 'string',
            'criteria.questionnaire_rules.rules.*.expected' => 'sometimes|string',
            'threshold' => 'required|integer|min:0|max:100',
        ]);

        $job->update([
            'shortlisting_criteria' => $validated['criteria'],
            'shortlist_threshold'   => $validated['threshold'],
        ]);

        return response()->json(['message' => 'Criteria saved.', 'threshold' => $job->shortlist_threshold]);
    }

    /**
     * POST /jobs/{id}/shortlist-preview
     * Dry run — returns per-candidate scores without saving anything.
     */
    public function previewShortlist(Request $request, $id)
    {
        $job = JobPosting::findOrFail($id);

        $validated = $request->validate([
            'criteria'  => 'required|array',
            'criteria.min_experience_years' => 'sometimes|array',
            'criteria.min_experience_years.enabled' => 'sometimes|boolean',
            'criteria.min_experience_years.value' => 'sometimes|integer|min:0',
            'criteria.min_experience_years.weight' => 'sometimes|integer|min:0',
            'criteria.education_level' => 'sometimes|array',
            'criteria.education_level.enabled' => 'sometimes|boolean',
            'criteria.education_level.value' => 'sometimes|string|in:Certificate,Diploma,Degree,Masters,PhD',
            'criteria.education_level.weight' => 'sometimes|integer|min:0',
            'criteria.field_of_study' => 'sometimes|array',
            'criteria.field_of_study.enabled' => 'sometimes|boolean',
            'criteria.field_of_study.value' => 'sometimes|nullable|string',
            'criteria.field_of_study.weight' => 'sometimes|integer|min:0',
            'criteria.skills' => 'sometimes|array',
            'criteria.skills.enabled' => 'sometimes|boolean',
            'criteria.skills.value' => 'sometimes|array',
            'criteria.skills.value.*' => 'string',
            'criteria.skills.weight' => 'sometimes|integer|min:0',
            'criteria.certifications' => 'sometimes|array',
            'criteria.certifications.enabled' => 'sometimes|boolean',
            'criteria.certifications.value' => 'sometimes|array',
            'criteria.certifications.value.*' => 'string',
            'criteria.certifications.weight' => 'sometimes|integer|min:0',
            'criteria.software' => 'sometimes|array',
            'criteria.software.enabled' => 'sometimes|boolean',
            'criteria.software.value' => 'sometimes|array',
            'criteria.software.value.*' => 'string',
            'criteria.software.weight' => 'sometimes|integer|min:0',
            'criteria.expected_salary_range' => 'sometimes|array',
            'criteria.expected_salary_range.enabled' => 'sometimes|boolean',
            'criteria.expected_salary_range.min' => 'sometimes|nullable|integer|min:0',
            'criteria.expected_salary_range.max' => 'sometimes|nullable|integer|min:0',
            'criteria.questionnaire_rules' => 'sometimes|array',
            'criteria.questionnaire_rules.enabled' => 'sometimes|boolean',
            'criteria.questionnaire_rules.weight' => 'sometimes|integer|min:0',
            'criteria.questionnaire_rules.rules' => 'sometimes|array',
            'criteria.questionnaire_rules.rules.*.key' => 'required_with:criteria.questionnaire_rules.enabled|string',
            'criteria.questionnaire_rules.rules.*.accepted_answers' => 'required_with:criteria.questionnaire_rules.enabled|array',
            'criteria.questionnaire_rules.rules.*.accepted_answers.*' => 'string',
            'criteria.questionnaire_rules.rules.*.expected' => 'sometimes|string',
            'threshold' => 'required|integer|min:0|max:100',
        ]);

        $service  = new AutoShortlistService();
        $results  = $service->preview($job, $validated['criteria'], $validated['threshold']);
        $wouldPass = collect($results)->where('would_pass', true)->count();
        $wouldReturnToNew = collect($results)
            ->where('current_status', 'Shortlisted')
            ->where('would_pass', false)
            ->count();

        return response()->json([
            'total'       => count($results),
            'would_pass'  => $wouldPass,
            'would_return_to_new' => $wouldReturnToNew,
            'candidates'  => $results,
        ]);
    }

    /**
     * POST /jobs/{id}/run-shortlist
     * Apply shortlisting — scores, promotes, demotes, saves.
     */
    public function runShortlist(Request $request, $id)
    {
        $job = JobPosting::findOrFail($id);

        $validated = $request->validate([
            'criteria'  => 'required|array',
            'criteria.min_experience_years' => 'sometimes|array',
            'criteria.min_experience_years.enabled' => 'sometimes|boolean',
            'criteria.min_experience_years.value' => 'sometimes|integer|min:0',
            'criteria.min_experience_years.weight' => 'sometimes|integer|min:0',
            'criteria.education_level' => 'sometimes|array',
            'criteria.education_level.enabled' => 'sometimes|boolean',
            'criteria.education_level.value' => 'sometimes|string|in:Certificate,Diploma,Degree,Masters,PhD',
            'criteria.education_level.weight' => 'sometimes|integer|min:0',
            'criteria.field_of_study' => 'sometimes|array',
            'criteria.field_of_study.enabled' => 'sometimes|boolean',
            'criteria.field_of_study.value' => 'sometimes|nullable|string',
            'criteria.field_of_study.weight' => 'sometimes|integer|min:0',
            'criteria.skills' => 'sometimes|array',
            'criteria.skills.enabled' => 'sometimes|boolean',
            'criteria.skills.value' => 'sometimes|array',
            'criteria.skills.value.*' => 'string',
            'criteria.skills.weight' => 'sometimes|integer|min:0',
            'criteria.certifications' => 'sometimes|array',
            'criteria.certifications.enabled' => 'sometimes|boolean',
            'criteria.certifications.value' => 'sometimes|array',
            'criteria.certifications.value.*' => 'string',
            'criteria.certifications.weight' => 'sometimes|integer|min:0',
            'criteria.software' => 'sometimes|array',
            'criteria.software.enabled' => 'sometimes|boolean',
            'criteria.software.value' => 'sometimes|array',
            'criteria.software.value.*' => 'string',
            'criteria.software.weight' => 'sometimes|integer|min:0',
            'criteria.expected_salary_range' => 'sometimes|array',
            'criteria.expected_salary_range.enabled' => 'sometimes|boolean',
            'criteria.expected_salary_range.min' => 'sometimes|nullable|integer|min:0',
            'criteria.expected_salary_range.max' => 'sometimes|nullable|integer|min:0',
            'criteria.questionnaire_rules' => 'sometimes|array',
            'criteria.questionnaire_rules.enabled' => 'sometimes|boolean',
            'criteria.questionnaire_rules.weight' => 'sometimes|integer|min:0',
            'criteria.questionnaire_rules.rules' => 'sometimes|array',
            'criteria.questionnaire_rules.rules.*.key' => 'required_with:criteria.questionnaire_rules.enabled|string',
            'criteria.questionnaire_rules.rules.*.accepted_answers' => 'required_with:criteria.questionnaire_rules.enabled|array',
            'criteria.questionnaire_rules.rules.*.accepted_answers.*' => 'string',
            'criteria.questionnaire_rules.rules.*.expected' => 'sometimes|string',
            'threshold' => 'required|integer|min:0|max:100',
        ]);

        // Persist the criteria used
        $job->update([
            'shortlisting_criteria' => $validated['criteria'],
            'shortlist_threshold'   => $validated['threshold'],
        ]);

        $service = new AutoShortlistService();
        $summary = $service->apply($job, $validated['criteria'], $validated['threshold']);

        return response()->json([
            'message'         => "Shortlisting complete. {$summary['shortlisted']} shortlisted, {$summary['returned_to_new']} returned to New.",
            'shortlisted'     => $summary['shortlisted'],
            'returned_to_new' => $summary['returned_to_new'],
            'total_scored'    => $summary['total_scored'],
        ]);
    }

    // ==========================================
    // BACKGROUND CHECKS
    // ==========================================

    /**
     * PATCH /hr/recruitment/admin/candidates/{id}/background-check
     * Update background check status, notes, documents.
     */
    public function updateBackgroundCheck(Request $request, $id)
    {
        $validated = $request->validate([
            'background_check_status' => 'nullable|in:Pending,Initiated',
            'background_check_notes' => 'nullable|string',
            'background_check_documents' => 'nullable|array',
            'background_check_documents.*' => 'string',
            'background_check_files' => 'nullable|array',
            'background_check_files.*' => 'file|max:10240',
        ]);

        $candidate = Candidate::findOrFail($id);

        $updates = [
            'background_check_status' => $validated['background_check_status'] ?? $candidate->background_check_status,
            'background_check_notes' => $validated['background_check_notes'] ?? $candidate->background_check_notes,
            'background_check_documents' => $this->resolveBackgroundCheckDocuments($request, $candidate),
        ];

        if (($updates['background_check_status'] ?? null) === 'Initiated') {
            $updates['status'] = 'Background Check';
        }

        $candidate->update($updates);

        return response()->json($candidate);
    }

    /**
     * POST /hr/recruitment/admin/candidates/{id}/background-check/start
     * Initiate background check for a candidate.
     */
    public function startBackgroundCheck($id)
    {
        $candidate = Candidate::findOrFail($id);
        
        $candidate->update([
            'status' => 'Background Check',
            'background_check_status' => 'Initiated',
            'background_check_completed_at' => null,
        ]);

        return response()->json([
            'message' => 'Background check initiated.',
            'candidate' => $candidate,
        ]);
    }

    /**
     * POST /hr/recruitment/admin/candidates/{id}/background-check/complete
     * Complete background check with outcome.
     */
    public function completeBackgroundCheck(Request $request, $id)
    {
        $validated = $request->validate([
            'outcome' => 'required|in:Passed,Failed',
            'notes' => 'nullable|string',
            'background_check_documents' => 'nullable|array',
            'background_check_documents.*' => 'string',
            'background_check_files' => 'nullable|array',
            'background_check_files.*' => 'file|max:10240',
        ]);

        $candidate = Candidate::findOrFail($id);
        
        $newStatus = $validated['outcome'] === 'Passed' ? 'Offered' : 'Rejected';
        
        $candidate->update([
            'status' => $newStatus,
            'background_check_status' => $validated['outcome'],
            'background_check_notes' => $validated['notes'] ?? $candidate->background_check_notes,
            'background_check_documents' => $this->resolveBackgroundCheckDocuments($request, $candidate),
            'background_check_completed_at' => now(),
        ]);

        return response()->json([
            'message' => "Background check completed. Candidate moved to {$newStatus}.",
            'candidate' => $candidate,
        ]);
    }

    private function resolveBackgroundCheckDocuments(Request $request, Candidate $candidate): array
    {
        $documents = collect($request->input('background_check_documents', $candidate->background_check_documents ?? []))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values();

        if ($request->hasFile('background_check_files')) {
            foreach ((array) $request->file('background_check_files') as $file) {
                if (!$file) {
                    continue;
                }

                $path = $file->store('recruitment/background-checks', 'public');
                $documents->push(Storage::disk('public')->url($path));
            }
        }

        return $documents->unique()->values()->all();
    }

    private function splitPostingList(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        return collect(preg_split('/[\r\n,;]+/', $value))
            ->map(fn ($item) => trim(preg_replace('/^[-*\s]+/', '', (string) $item)))
            ->filter()
            ->unique(fn ($item) => strtolower($item))
            ->values()
            ->all();
    }

    private function normalizeSoftwareList($software): array
    {
        return collect((array) $software)
            ->map(function ($item) {
                if (is_array($item)) {
                    return $item['name'] ?? $item['software'] ?? $item['tool'] ?? null;
                }

                if (is_object($item)) {
                    return $item->name ?? $item->software ?? $item->tool ?? null;
                }

                return $item;
            })
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function buildSuggestedShortlistCriteria(JobPosting $job): array
    {
        $skills = $this->splitPostingList($job->skillset);
        $software = $this->splitPostingList($job->software_tools);

        return [
            'skills' => [
                'enabled' => ! empty($skills),
                'value' => $skills,
                'weight' => 25,
            ],
            'software' => [
                'enabled' => ! empty($software),
                'value' => $software,
                'weight' => 10,
            ],
        ];
    }
}
