<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceRecord extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_records';

    protected $fillable = [
        'employee_id',
        'work_schedule_id',
        'date',
        'clock_in',
        'clock_out',
        'device_clock_in',
        'device_clock_out',
        'status',
        'is_holiday_work',
        'holiday_name',
        'work_hours',
        'overtime_hours',
        'proposed_overtime_hours',
        'approved_overtime_hours',
        'overtime_status',
        'is_manual',
        'correction_reason',
        'corrected_by',
        'corrected_at',
        'notes',
        'synced_at',
    ];

    protected $casts = [
        'date' => 'date',
        'work_hours' => 'float',
        'overtime_hours' => 'float',
        'proposed_overtime_hours' => 'float',
        'approved_overtime_hours' => 'float',
        'is_manual' => 'boolean',
        'is_holiday_work' => 'boolean',
        'corrected_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    protected $appends = [
        'late_by_minutes',
        'early_by_minutes',
    ];

    const STATUS_PRESENT = 'present';
    const STATUS_ABSENT = 'absent';
    const STATUS_LATE = 'late';
    const STATUS_EARLY_DEPARTURE = 'early_departure';
    const STATUS_HALF_DAY = 'half_day';
    const STATUS_MISSING_CLOCK_OUT = 'missing_clock_out';
    const STATUS_ON_LEAVE = 'on_leave';
    const STATUS_HOLIDAY = 'holiday';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_EARLY_DEPARTURE,
        self::STATUS_HALF_DAY,
        self::STATUS_MISSING_CLOCK_OUT,
        self::STATUS_ON_LEAVE,
        self::STATUS_HOLIDAY,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkSchedule::class, 'work_schedule_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(HRAuditLog::class, 'model_id')
            ->where('model_type', self::class)
            ->latest();
    }

    public function overtimeEntry(): HasOne
    {
        return $this->hasOne(OTEntry::class);
    }

    public function scopeByDate($query, string $date)
    {
        return $query->where('date', $date);
    }

    public function scopeByDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeByEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->whereHas('employee', function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('employee_id', 'like', "%{$search}%");
        });
    }

    public function scopeWithOvertime($query)
    {
        return $query->where('overtime_hours', '>', 0);
    }

    public function getLateByMinutesAttribute(): int
    {
        if (!$this->clock_in) {
            return 0;
        }

        $shiftStart = $this->workSchedule?->shift_start ?? config('hikvision.shift_start', '08:00');
        $clockIn = Carbon::createFromFormat('H:i:s', strlen($this->clock_in) === 5 ? $this->clock_in . ':00' : $this->clock_in);
        $start = Carbon::createFromFormat('H:i:s', strlen($shiftStart) === 5 ? $shiftStart . ':00' : $shiftStart);

        return max(0, $start->diffInMinutes($clockIn, false));
    }

    public function getEarlyByMinutesAttribute(): int
    {
        if (!$this->clock_out) {
            return 0;
        }

        $shiftEnd = $this->workSchedule?->shift_end ?? config('hikvision.shift_end', '17:00');
        $clockOut = Carbon::createFromFormat('H:i:s', strlen($this->clock_out) === 5 ? $this->clock_out . ':00' : $this->clock_out);
        $end = Carbon::createFromFormat('H:i:s', strlen($shiftEnd) === 5 ? $shiftEnd . ':00' : $shiftEnd);

        return max(0, $clockOut->diffInMinutes($end, false));
    }
}
