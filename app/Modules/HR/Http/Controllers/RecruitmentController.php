<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Candidate;
use App\Modules\HR\Models\CandidateDocument;
use App\Modules\HR\Http\Requests\CandidateApplicationRequest;
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
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
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
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'status' => 'required|in:Draft,Published,Closed,On Hold'
        ]);

        $job->update($validated);
        return response()->json($job);
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
            'status' => 'required|in:New,Shortlisted,Interviewing,Offered,Hired,Rejected'
        ]);

        $candidate = Candidate::findOrFail($id);
        $candidate->update(['status' => $validated['status']]);

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
}
