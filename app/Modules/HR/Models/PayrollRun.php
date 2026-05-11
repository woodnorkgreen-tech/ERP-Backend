<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_month',
        'status',
        'snapshot_settings',
        'total_gross',
        'total_net',
        'total_statutory',
        'created_by'
    ];

    protected $casts = [
        'snapshot_settings' => 'array',
        'total_gross' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_statutory' => 'decimal:2'
    ];

    /**
     * Get the payslips associated with this run.
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * Get the user who created this run.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
