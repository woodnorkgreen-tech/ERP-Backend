<?php

return [
    'host' => env('HIKVISION_HOST', '192.168.1.200'),
    'port' => env('HIKVISION_PORT', 80),
    'username' => env('HIKVISION_USERNAME', 'admin'),
    'password' => env('HIKVISION_PASSWORD', 'admin123'),
    'device_id' => env('HIKVISION_DEVICE_ID', 'HIKVISION_001'),
    'device_name' => env('HIKVISION_DEVICE_NAME', 'Main Entrance Reader'),

    // Standard shift hours used for status and overtime calculation
    'shift_start'    => env('HIKVISION_SHIFT_START', '08:00'),
    'shift_end'      => env('HIKVISION_SHIFT_END', '17:00'),
    'overtime_start' => env('HIKVISION_OVERTIME_START', '18:00'), // overtime only counts after this time
    'late_threshold_minutes' => env('HIKVISION_LATE_THRESHOLD', 10), // minutes after shift_start = late
];
