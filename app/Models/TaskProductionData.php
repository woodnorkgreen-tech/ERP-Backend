<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskProductionData extends Model
{
    use HasFactory;

    protected $table = 'task_production_data';

    protected $fillable = [
        'task_id',
        'materials_imported',
        'last_materials_import_date',
    ];

    protected $casts = [
        'materials_imported' => 'boolean',
        'last_materials_import_date' => 'datetime',
    ];

    /**
     * Get the task that owns this production data
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'task_id');
    }

    /**
     * Get all production elements
     */
    public function productionElements(): HasMany
    {
        return $this->hasMany(ProductionElement::class, 'production_data_id');
    }

    /**
     * Get all quality checkpoints
     */
    public function qualityCheckpoints(): HasMany
    {
        return $this->hasMany(ProductionQualityCheckpoint::class, 'production_data_id');
    }

    /**
     * Get all issues
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ProductionIssue::class, 'production_data_id');
    }

    /**
     * Get all completion criteria
     */
    public function completionCriteria(): HasMany
    {
        return $this->hasMany(ProductionCompletionCriterion::class, 'production_data_id');
    }
}
