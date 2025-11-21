<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionCompletionCriterion extends Model
{
    use HasFactory;

    protected $table = 'production_completion_criteria';

    protected $fillable = [
        'production_data_id',
        'description',
        'category',
        'met',
        'is_custom',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'met' => 'boolean',
        'is_custom' => 'boolean',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the production data that owns this criterion
     */
    public function productionData(): BelongsTo
    {
        return $this->belongsTo(TaskProductionData::class, 'production_data_id');
    }
}
