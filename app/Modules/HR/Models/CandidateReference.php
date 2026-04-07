<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateReference extends Model
{
    protected $table = 'hr_candidate_references';
    
    protected $guarded = ['id'];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }
}
