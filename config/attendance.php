<?php

return [
    'holiday_api_url' => env(
        'ATTENDANCE_HOLIDAY_API_URL',
        'https://date.nager.at/api/v3/PublicHolidays/{year}/KE'
    ),
    'holiday_import_years_ahead' => (int) env('ATTENDANCE_HOLIDAY_YEARS_AHEAD', 1),
    'holiday_import_years_back' => (int) env('ATTENDANCE_HOLIDAY_YEARS_BACK', 1),
    'manual_sync_connection' => env('ATTENDANCE_MANUAL_SYNC_CONNECTION', 'sync'),
    'sync_queued_timeout_minutes' => (int) env('ATTENDANCE_SYNC_QUEUED_TIMEOUT_MINUTES', 2),
    'sync_running_timeout_minutes' => (int) env('ATTENDANCE_SYNC_RUNNING_TIMEOUT_MINUTES', 20),
    'holiday_ca_bundle' => env(
        'ATTENDANCE_HOLIDAY_CA_BUNDLE',
        storage_path('app/certs/cacert.pem')
    ),

    // Moon-sighting holidays may be gazetted after general calendars publish.
    'kenya_holiday_overrides' => [
        2026 => [
            [
                'date' => '2026-03-20',
                'name' => 'Idd-ul-Fitr',
                'source_reference' => 'Kenya Gazette / Public Holidays Act',
            ],
            [
                'date' => '2026-05-27',
                'name' => 'Idd-ul-Azha',
                'source_reference' => 'Kenya Gazette Special Issue, 26 May 2026',
            ],
        ],
    ],
];
