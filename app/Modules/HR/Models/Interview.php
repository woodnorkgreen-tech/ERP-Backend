<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class Interview extends Model
{
    use SoftDeletes;

    protected $table = 'hr_interviews';

    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_at'   => 'datetime',
        'interviewer_ids' => 'array',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }

    public function jobPosting()
    {
        return $this->belongsTo(JobPosting::class, 'job_posting_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolve interviewer User models from the stored IDs.
     */
    public function getInterviewersAttribute()
    {
        if (empty($this->interviewer_ids)) return collect();
        return User::whereIn('id', $this->interviewer_ids)->get(['id', 'name', 'email']);
    }
}
