<?php

namespace App\Modules\Design\Models;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignJob extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_enquiry_id',
        'project_id',
        'client_id',
        'job_number',
        'title',
        'source_type',
        'sync_origin',
        'auto_synced_at',
        'status',
        'priority',
        'due_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'auto_synced_at' => 'datetime',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DesignItem::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DesignDocument::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
