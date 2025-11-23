<?php

namespace App\Modules\ArchivalTask\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivalSetupItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'archival_report_id',
        'deliverable_item',
        'assigned_technician',
        'site_section',
        'status',
        'notes',
    ];

    /**
     * Relationships
     */
    public function archivalReport(): BelongsTo
    {
        return $this->belongsTo(ArchivalReport::class);
    }
}
