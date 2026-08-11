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

    protected $fillable = [
        'design_job_id',
        'design_type_id',
        'project_deliverable_id',
        'stream',
        'title',
        'description',
        'status',
        'assigned_to',
        'quantity',
        'dimension_unit',
        'length_value',
        'width_value',
        'height_value',
        'length_m',
        'width_m',
        'height_m',
        'print_material_id',
        'print_notes',
        'concept_notes',
        'technical_notes',
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
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'print_ready_at' => 'datetime',
        'production_ready_at' => 'datetime',
    ];

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

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
}
