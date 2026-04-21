<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CandidateApplicationRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Public endpoint
    }

    public function rules()
    {
        return [
            'job_posting_id' => 'required|exists:hr_job_postings,id',
            
            // Bio-Data
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255', // Usually unique verification per job later, but simplistic for now
            'phone' => 'required|string|max:50',
            'gender' => 'required|string',
            'dob' => 'required|date',
            'nationality' => 'required|string',
            'county_of_residence' => 'required|string',
            'id_type' => 'required|string',
            'id_number' => 'required|string',
            'kra_pin' => 'nullable|string',
            'marital_status' => 'required|string',
            
            // JSON Arrays holding the multi-step records
            'experiences' => 'nullable|array',
            'experiences.*.employer' => 'required_with:experiences|string|max:255',
            'experiences.*.job_title' => 'required_with:experiences|string|max:255',
            'experiences.*.start_date' => 'required_with:experiences|date',
            'experiences.*.end_date' => 'nullable|date',
            'experiences.*.is_current' => 'nullable|boolean',
            'experiences.*.industry' => 'nullable|string',
            'experiences.*.employment_type' => 'nullable|string',
            'experiences.*.responsibilities' => 'nullable|string',

            'educations' => 'nullable|array',
            'educations.*.institution' => 'required_with:educations|string|max:255',
            'educations.*.level_of_study' => 'required_with:educations|string|max:255',
            'educations.*.field_of_study' => 'nullable|string|max:255',
            'educations.*.grade' => 'nullable|string|max:255',
            'educations.*.graduation_year' => 'nullable|integer',

            'references_list' => 'nullable|array',
            'references_list.*.name' => 'required_with:references_list|string|max:255',
            'references_list.*.phone' => 'required_with:references_list|string|max:255',
            'references_list.*.email' => 'nullable|email|max:255',
            'references_list.*.organization' => 'nullable|string|max:255',
            'references_list.*.position' => 'nullable|string|max:255',
            'references_list.*.relationship' => 'nullable|string|max:255',

            // Primitive Arrays/JSON
            'skills' => 'nullable|array',
            'software_proficiency' => 'nullable|array',
            'certifications' => 'nullable|array',
            'questionnaire_responses' => 'nullable|array',

            // Files
            'cv_file' => 'required|file|mimes:pdf,doc,docx|max:5120',
            'cover_letter' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'portfolio' => 'nullable|file|mimes:pdf,zip|max:10240',
        ];
    }
}
