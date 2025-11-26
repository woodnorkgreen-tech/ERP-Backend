<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_budget_data_id',
        'version_number',
        'label',
        'data',
        'created_by',
        'materials_version_id',
        'source_updated_at'
    ];

    protected $casts = [
        'data' => 'array',
        'source_updated_at' => 'datetime'
    ];

    public function budgetData(): BelongsTo
    {
        return $this->belongsTo(TaskBudgetData::class, 'task_budget_data_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function materialVersion(): BelongsTo
    {
        return $this->belongsTo(MaterialVersion::class, 'materials_version_id');
    }
}
