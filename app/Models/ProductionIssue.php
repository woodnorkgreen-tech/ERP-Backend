<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_data_id',
        'title',
        'description',
        'category',
        'status',
        'priority',
        'reported_by',
        'reported_date',
        'resolved_date',
        'resolution',
    ];

    protected $casts = [
        'reported_date' => 'datetime',
        'resolved_date' => 'datetime',
    ];

    /**
     * Get the production data that owns this issue
     */
    public function productionData(): BelongsTo
    {
        return $this->belongsTo(TaskProductionData::class, 'production_data_id');
    }
}
