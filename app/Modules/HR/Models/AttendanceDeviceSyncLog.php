<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDeviceSyncLog extends Model
{
    protected $table = 'attendance_device_sync_logs';

    protected $fillable = [
        'device_id',
        'device_name',
        'synced_at',
        'records_imported',
        'records_processed',
        'status',
        'error',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'records_imported' => 'integer',
        'records_processed' => 'integer',
    ];

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL = 'partial';

    public function rawEvents(): HasMany
    {
        return $this->hasMany(AttendanceDeviceRawEvent::class, 'sync_log_id');
    }
}
