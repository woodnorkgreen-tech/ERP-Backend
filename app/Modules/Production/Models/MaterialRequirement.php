<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequirement extends Model
{
    use HasFactory;

    protected $table = 'material_requirements';

    protected $fillable = [
        'job_card_id',
        'material_name',
        'quantity_used',
        'unit',
        'notes',
    ];

    protected $casts = [
        'quantity_used' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the job card for this material requirement.
     */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }
}
