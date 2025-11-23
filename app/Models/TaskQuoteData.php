<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskQuoteData extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_task_id',
        'project_info',
        'budget_imported',
        'budget_imported_at',
        'budget_updated_at',
        'budget_version',
        'materials',
        'labour',
        'expenses',
        'logistics',
        'margins',
        'custom_margins',
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
        'budget_imported_at' => 'datetime',
        'budget_updated_at' => 'datetime',
        'materials' => 'array',
        'labour' => 'array',
        'expenses' => 'array',
        'logistics' => 'array',
        'margins' => 'array',
        'custom_margins' => 'array',
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

    public function versions(): HasMany
    {
        return $this->hasMany(\App\Models\QuoteVersion::class, 'task_quote_data_id'); // Assuming QuoteVersion model is in App\Models
    }
}
