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
        'range_from',
        'range_to',
        'records_fetched',
        'records_imported',
        'records_duplicate',
        'records_processed',
        'records_unmapped',
        'records_failed',
        'status',
        'error',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'range_from' => 'datetime',
        'range_to' => 'datetime',
        'records_fetched' => 'integer',
        'records_imported' => 'integer',
        'records_duplicate' => 'integer',
        'records_processed' => 'integer',
        'records_unmapped' => 'integer',
        'records_failed' => 'integer',
    ];

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIAL = 'partial';

    public function rawEvents(): HasMany
    {
        return $this->hasMany(AttendanceDeviceRawEvent::class, 'sync_log_id');
    }
}
