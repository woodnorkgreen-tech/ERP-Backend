<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compensation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'compensations';

    protected $fillable = [
        'employee_id',
        'technical_labour_id',
        'comp_date',
        'type',
        'hours',
        'project_conflict_check',
        'status',
        'supervisor_approved_by',
        'supervisor_approved_at',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'comp_date' => 'date',
        'supervisor_approved_at' => 'datetime',
        'approved_at' => 'datetime',
        'hours' => 'decimal:2',
        'project_conflict_check' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function technicalLabour(): BelongsTo
    {
        return $this->belongsTo(TechnicalLabour::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function supervisorApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_approved_by');
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class, 'compensation_id');
    }
}
