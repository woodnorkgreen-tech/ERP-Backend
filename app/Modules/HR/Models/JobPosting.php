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
        'application_deadline' => 'date',
    ];

    protected $appends = [
        'is_accepting_applications',
        'public_status',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class, 'job_posting_id');
    }

    public function repostedFrom()
    {
        return $this->belongsTo(self::class, 'reposted_from_id');
    }

    public function reposts()
    {
        return $this->hasMany(self::class, 'reposted_from_id');
    }

    public function getIsAcceptingApplicationsAttribute(): bool
    {
        if ($this->status !== 'Published') {
            return false;
        }

        if (! $this->application_deadline) {
            return true;
        }

        return $this->application_deadline->copy()->endOfDay()->isFuture();
    }

    public function getPublicStatusAttribute(): string
    {
        return $this->is_accepting_applications ? 'Open' : 'Closed';
    }
}
