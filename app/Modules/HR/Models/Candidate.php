<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Candidate extends Model
{
    use SoftDeletes, Notifiable;

    protected $table = 'hr_candidates';
    
    protected $guarded = ['id'];

    protected $casts = [
        'dob' => 'date',
        'skills' => 'array',
        'software_proficiency' => 'array',
        'certifications' => 'array',
        'questionnaire_responses' => 'array',
        'shortlist_breakdown' => 'array',
        'background_check_documents' => 'array',
        'background_check_completed_at' => 'datetime',
    ];

    public function jobPosting()
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    public function experiences()
    {
        return $this->hasMany(CandidateExperience::class, 'candidate_id');
    }

    public function educations()
    {
        return $this->hasMany(CandidateEducation::class, 'candidate_id');
    }

    public function references()
    {
        return $this->hasMany(CandidateReference::class, 'candidate_id');
    }

    public function documents()
    {
        return $this->hasMany(CandidateDocument::class, 'candidate_id');
    }
}
