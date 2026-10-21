<?php

namespace App\Modules\Projects\Models;

use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectPrintChecklist extends Model
{
    protected $fillable = [
        'project_enquiry_id',
        'project_officer_name',
        'designer_name',
        'date_prepared',
        'created_by',
    ];

    protected $casts = [
        'date_prepared' => 'date:Y-m-d',
    ];

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'project_enquiry_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectPrintChecklistItem::class, 'project_print_checklist_id')
            ->orderBy('sort_order')->orderBy('id');
    }
}