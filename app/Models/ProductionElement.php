<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionElement extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_data_id',
        'material_id',
        'category',
        'name',
        'quantity',
        'unit',
        'specifications',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * Get the production data that owns this element
     */
    public function productionData(): BelongsTo
    {
        return $this->belongsTo(TaskProductionData::class, 'production_data_id');
    }
}
