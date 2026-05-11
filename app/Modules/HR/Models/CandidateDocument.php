<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateDocument extends Model
{
    protected $table = 'hr_candidate_documents';
    
    protected $guarded = ['id'];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }
}
