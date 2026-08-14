<?php

namespace App\Modules\Printing\Models;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    public const LOCKED_STATUSES = ['completed', 'cancelled'];

    protected $fillable = [
        'design_handoff_id',
        'design_item_id',
        'design_job_id',
        'project_enquiry_id',
        'project_id',
        'client_id',
        'job_number',
        'project_name',
        'client_name',
        'title',
        'description',
        'final_artwork_url',
        'final_artwork_document_id',
        'artwork_version',
        'design_height_m',
        'design_length_m',
        'print_width_m',
        'running_length_m',
        'artwork_quantity',
        'order_type',
        'reprint_of_job_id',
        'reprint_reason',
        'status',
        'due_date',
        'scheduled_at',
        'started_at',
        'completed_at',
        'operator_id',
        'machine_asset_id',
        'machine_name_snapshot',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'due_date' => 'date',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'artwork_version' => 'integer',
        'design_height_m' => 'decimal:3',
        'design_length_m' => 'decimal:3',
        'print_width_m' => 'decimal:3',
        'running_length_m' => 'decimal:3',
        'artwork_quantity' => 'decimal:3',
    ];

    public function handoff(): BelongsTo
    {
        return $this->belongsTo(DesignHandoff::class, 'design_handoff_id');
    }

    public function designItem(): BelongsTo
    {
        return $this->belongsTo(DesignItem::class, 'design_item_id');
    }

    public function designJob(): BelongsTo
    {
        return $this->belongsTo(DesignJob::class, 'design_job_id');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'machine_asset_id');
    }

    public function originalJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprint_of_job_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(PrintJobConsumption::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrintJobEvent::class);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }
}
