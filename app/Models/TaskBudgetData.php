<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskBudgetData extends Model
{
    protected $fillable = [
        'enquiry_task_id', 'project_info', 'materials_data',
        'labour_data', 'expenses_data', 'logistics_data',
        'budget_summary', 'status', 'materials_imported_at',
        'materials_imported_from_task', 'materials_manually_modified',
        'materials_import_metadata', 'last_import_date'
    ];

    protected $casts = [
        'project_info' => 'array',
        'materials_data' => 'array',
        'labour_data' => 'array',
        'expenses_data' => 'array',
        'logistics_data' => 'array',
        'budget_summary' => 'array',
        'materials_imported_at' => 'datetime',
        'materials_manually_modified' => 'boolean',
        'materials_import_metadata' => 'array',
        'last_import_date' => 'datetime'
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    public function enquiry_task(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class);
    }

    public function budgetAdditions(): HasMany
    {
        return $this->hasMany(BudgetAddition::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class)->orderBy('version_number', 'desc');
    }
}
