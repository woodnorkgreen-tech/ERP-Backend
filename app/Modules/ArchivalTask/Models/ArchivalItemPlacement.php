<?php

namespace App\Modules\ArchivalTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivalItemPlacement extends Model
{
    use HasFactory;

    protected $fillable = [
        'archival_report_id',
        'section_area',
        'items_installed',
        'placement_accuracy',
        'observation',
    ];

    /**
     * Relationships
     */
    public function archivalReport(): BelongsTo
    {
        return $this->belongsTo(ArchivalReport::class);
    }
}
