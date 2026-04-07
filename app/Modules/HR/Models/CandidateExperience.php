<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateExperience extends Model
{
    protected $table = 'hr_candidate_experiences';
    
    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }
}
