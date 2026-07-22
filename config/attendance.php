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
        2025 => [
            [
                'date' => '2025-03-31',
                'name' => 'Idd-ul-Fitr',
                'source_reference' => 'Kenya Gazette Special Issue, 28 March 2025',
            ],
            [
                'date' => '2025-06-06',
                'name' => 'Idd-ul-Azha',
                'source_reference' => 'Kenya Gazette Special Issue, 4 June 2025',
            ],
        ],
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
        2027 => [
            [
                'date' => '2027-03-10',
                'name' => 'Idd-ul-Fitr',
                'source_reference' => 'Tentative Muslim calendar date pending Kenya Gazette declaration',
            ],
            [
                'date' => '2027-05-17',
                'name' => 'Idd-ul-Azha',
                'source_reference' => 'Tentative Muslim calendar date pending Kenya Gazette declaration',
            ],
        ],
    ],
];
