<?php

namespace App\Modules\logisticsTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_task_id',
        'name',
        'description',
        'quantity',
        'unit',
        'category',
        'main_category',
        // Report 79C: the registered asset a Tools & Equipment row refers to.
        'asset_id',
        'is_returnable',
        'sub_type',
        'element_category',
        'source',
        'weight',
        'special_handling',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_returnable' => 'boolean',
        'category' => 'string',
        'source' => 'string',
    ];

    // Relationships

    /** Report 79C: a reference to the asset register; never a hand-over by itself. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Assets\Models\Asset::class)->withTrashed();
    }

    public function logisticsTask(): BelongsTo
    {
        return $this->belongsTo(LogisticsTask::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // Scopes
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    // Helper methods
    public function getTotalWeightAttribute()
    {
        if (!$this->weight || !$this->quantity) {
            return null;
        }

        // Extract numeric value from weight string (e.g., "5 kg" -> 5)
        preg_match('/(\d+(?:\.\d+)?)/', $this->weight, $matches);
        $weightValue = $matches[1] ?? 0;

        return $weightValue * $this->quantity;
    }
}
