<?php

namespace App\Modules\Design\Models;

use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignItem extends Model
{
    use SoftDeletes;

    public const STREAM_GRAPHIC = 'graphic';
    public const STREAM_STRUCTURAL = 'structural';
    public const COMPLETED_STATUSES = ['done', 'print_ready', 'production_ready', 'handed_off'];

    protected $fillable = [
        'design_job_id',
        'design_type_id',
        'project_deliverable_id',
        'redesign_of_item_id',
        'redesign_of_print_job_id',
        'redesign_source',
        'redesign_reason',
        'redesign_requested_at',
        'stream',
        'destination',
        'title',
        'description',
        'status',
        'assigned_to',
        'assignment_source',
        'generated_scope_key',
        'last_synced_element_title',
        'source_element_archived_at',
        'not_required_by',
        'not_required_at',
        'not_required_reason',
        'quantity',
        'dimension_unit',
        'length_value',
        'width_value',
        'height_value',
        'length_m',
        'width_m',
        'height_m',
        'print_material_id',
        'application_surface',
        'bleed_per_side_m',
        'print_notes',
        'concept_notes',
        'technical_notes',
        'outsourced_partner_name',
        'outsourced_reason',
        'outsourced_reason_note',
        'outsourced_by',
        'outsourced_at',
        'submitted_at',
        'approved_at',
        'print_ready_at',
        'production_ready_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'length_value' => 'decimal:3',
        'width_value' => 'decimal:3',
        'height_value' => 'decimal:3',
        'length_m' => 'decimal:3',
        'width_m' => 'decimal:3',
        'height_m' => 'decimal:3',
        'bleed_per_side_m' => 'decimal:3',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'print_ready_at' => 'datetime',
        'production_ready_at' => 'datetime',
        'redesign_requested_at' => 'datetime',
        'outsourced_at' => 'datetime',
        'not_required_at' => 'datetime',
        'source_element_archived_at' => 'datetime',
    ];

    public function isOutsourced(): bool
    {
        return $this->outsourced_partner_name !== null;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(DesignJob::class, 'design_job_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DesignType::class, 'design_type_id');
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(ProjectDeliverable::class, 'project_deliverable_id');
    }

    public function redesignOfItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redesign_of_item_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notRequiredActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'not_required_by');
    }

    public function printMaterial(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterial::class, 'print_material_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DesignDocument::class);
    }

    public function bomItems(): HasMany
    {
        return $this->hasMany(DesignBomItem::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(DesignHandoff::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(DesignWorkSession::class)->latest('started_at');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DesignRevision::class)->orderByDesc('version_number');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(DesignChangeRequest::class)->latest('received_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(DesignUpdate::class)->latest();
    }
}
