<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodAuditLog extends Model
{
    protected $table = 'finance_period_audit_logs';

    protected $fillable = [
        'accounting_period_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'reason',
        'forced',
        'checklist',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'forced' => 'boolean',
        'checklist' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}