<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskQuoteData extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'project_info',
        'budget_imported',
        'materials',
        'labour',
        'expenses',
        'logistics',
        'margins',
        'discount_amount',
        'vat_percentage',
        'vat_enabled',
        'totals',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'project_info' => 'array',
        'budget_imported' => 'boolean',
        'materials' => 'array',
        'labour' => 'array',
        'expenses' => 'array',
        'logistics' => 'array',
        'margins' => 'array',
        'discount_amount' => 'decimal:2',
        'vat_percentage' => 'decimal:2',
        'vat_enabled' => 'boolean',
        'totals' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function enquiryTask(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Projects\Models\EnquiryTask::class, 'enquiry_task_id');
    }
}
