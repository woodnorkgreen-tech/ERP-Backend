<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDeviceRawEvent extends Model
{
    protected $table = 'attendance_device_raw_events';

    protected $fillable = [
        'person_id',
        'person_name',
        'event_datetime',
        'check_point',
        'department',
        'source',
        'sync_log_id',
    ];

    protected $casts = [
        'event_datetime' => 'datetime',
    ];

    const SOURCE_API_SYNC = 'api_sync';
    const SOURCE_CSV_UPLOAD = 'csv_upload';

    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceDeviceSyncLog::class, 'sync_log_id');
    }
}
