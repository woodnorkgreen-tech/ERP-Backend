<?php

namespace App\Modules\Finance\CostCollector\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Finance\Models\PeriodAuditLog;

class AccountingPeriod extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_LOCKED = 'locked';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'year', 'month', 'starts_on', 'ends_on', 'status',
        'locked_by', 'locked_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'locked_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public static function forDate(CarbonInterface $date): ?self
    {
        return static::whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PeriodAuditLog::class);
    }

    public function latestAuditLog(): HasOne
    {
        return $this->hasOne(PeriodAuditLog::class)->latestOfMany();
    }
}
