<?php

return [
    'name'        => 'Logistics',
    'description' => 'Fleet and Delivery Management Module',
    'google_maps_key' => env('GOOGLE_MAPS_KEY', ''),
    'google_maps_server_key' => env('GOOGLE_MAPS_SERVER_KEY', ''),

    // SMS notifications (TextSMS). Keep the keys in .env, never in the repo.
    'sms' => [
        'enabled'    => env('TEXTSMS_ENABLED', false),
        'url'        => env('TEXTSMS_API_URL', 'https://sms.textsms.co.ke/api/services/sendsms/'),
        'api_key'    => env('TEXTSMS_API_KEY', ''),
        'partner_id' => env('TEXTSMS_PARTNER_ID', ''),
        'shortcode'  => env('TEXTSMS_SHORTCODE', 'TextSMS'),
    ],
];
