<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobPosting extends Model
{
    use SoftDeletes;

    protected $table = 'hr_job_postings';
    
    protected $guarded = ['id'];

    protected $casts = [
        'shortlisting_criteria' => 'array',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class, 'job_posting_id');
    }
}
