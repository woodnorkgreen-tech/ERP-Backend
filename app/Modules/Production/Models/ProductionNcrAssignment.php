<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionNcrAssignment extends Model
{
    use HasFactory;

    protected $table = 'production_ncr_assignments';

    protected $fillable = [
        'ncr_id',
        'assigned_to_user_id',
        'assigned_department',
        'assigned_workstation',
        'assignment_role',
        'notes',
        'status',
        'due_date',
        'assigned_by',
        'assigned_at',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(ProductionNcr::class, 'ncr_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_by');
    }
}
