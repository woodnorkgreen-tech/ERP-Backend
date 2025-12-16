<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionQualityCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_data_id',
        'category_id',
        'category_name',
        'status',
        'quality_score',
        'checked_by',
        'checked_at',
        'priority',
        'notes',
        'checklist',
    ];

    protected $casts = [
        'quality_score' => 'integer',
        'checked_at' => 'datetime',
        'checklist' => 'array',
    ];

    /**
     * Get the production data that owns this checkpoint
     */
    public function productionData(): BelongsTo
    {
        return $this->belongsTo(TaskProductionData::class, 'production_data_id');
    }
}
