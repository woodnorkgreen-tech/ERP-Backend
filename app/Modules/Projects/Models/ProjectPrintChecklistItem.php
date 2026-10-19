<?php

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPrintChecklistItem extends Model
{
    protected $fillable = [
        'item_artwork',
        'print_measurement',
        'print_length',
        'print_width',
        'print_height',
        'material',
        'quantity',
        'structure_surface',
        'bleed_allowance',
        'client_approval_status',
        'final_artwork_check',
        'printing_status',
        'print_technician',
        'sort_order',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ProjectPrintChecklist::class, 'project_print_checklist_id');
    }
}
