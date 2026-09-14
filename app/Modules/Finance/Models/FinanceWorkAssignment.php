<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceWorkAssignment extends Model
{
    protected $fillable = ['work_type', 'source_id', 'assigned_to', 'assigned_by', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
