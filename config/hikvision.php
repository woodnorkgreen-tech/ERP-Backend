<?php

return [
    'host' => env('HIKVISION_HOST', '192.168.1.200'),
    'port' => env('HIKVISION_PORT', 80),
    'username' => env('HIKVISION_USERNAME', 'admin'),
    'password' => env('HIKVISION_PASSWORD'),
    'device_id' => env('HIKVISION_DEVICE_ID', 'HIKVISION_001'),
    'device_name' => env('HIKVISION_DEVICE_NAME', 'Main Entrance Reader'),
    'device_timezone' => env('HIKVISION_DEVICE_TIMEZONE', 'Africa/Nairobi'),
    'storage_timezone' => env('ATTENDANCE_STORAGE_TIMEZONE', 'Africa/Nairobi'),
    'sync_lookback_days' => (int) env('HIKVISION_SYNC_LOOKBACK_DAYS', 30),
    'sync_overlap_hours' => (int) env('HIKVISION_SYNC_OVERLAP_HOURS', 24),
    'sync_chunk_hours' => (int) env('HIKVISION_SYNC_CHUNK_HOURS', 1),
    'sync_chunk_minutes' => env('HIKVISION_SYNC_CHUNK_MINUTES'),
    'connect_timeout_seconds' => (int) env('HIKVISION_CONNECT_TIMEOUT_SECONDS', 10),
    'request_timeout_seconds' => (int) env('HIKVISION_REQUEST_TIMEOUT_SECONDS', 30),
    'retry_times' => (int) env('HIKVISION_RETRY_TIMES', 3),
    'retry_sleep_ms' => (int) env('HIKVISION_RETRY_SLEEP_MS', 500),
    'max_pages' => (int) env('HIKVISION_MAX_PAGES', 10000),

    // Standard shift hours used for status and overtime calculation
    'shift_start'    => env('HIKVISION_SHIFT_START', '08:00'),
    'shift_end'      => env('HIKVISION_SHIFT_END', '17:00'),
    'overtime_start' => env('HIKVISION_OVERTIME_START', '18:00'), // overtime only counts after this time
    'late_threshold_minutes' => env('HIKVISION_LATE_THRESHOLD', 10), // minutes after shift_start = late
];
