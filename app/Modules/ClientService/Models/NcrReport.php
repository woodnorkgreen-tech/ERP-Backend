<?php

namespace App\Modules\ClientService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ProjectEnquiry;
use App\Models\HandoverSurvey;
use App\Models\User;

class NcrReport extends Model
{
    protected $table = 'ncr_reports';

    const CATEGORIES = ['quality', 'delivery', 'communication', 'design', 'installation', 'other'];
    const STATUSES   = ['open', 'in_progress', 'resolved', 'closed'];

    protected $fillable = [
        'project_enquiry_id',
        'handover_survey_id',
        'raised_by',
        'assigned_department',
        'title',
        'category',
        'description',
        'root_cause',
        'corrective_action',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(HandoverSurvey::class, 'handover_survey_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
