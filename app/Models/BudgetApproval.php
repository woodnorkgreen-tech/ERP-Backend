<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetApproval extends Model
{
    protected $fillable = [
        'task_budget_data_id', 'approved_by', 'status', 'comments', 'approved_at'
    ];

    protected $casts = [
        'approved_at' => 'datetime'
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(TaskBudgetData::class, 'task_budget_data_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
