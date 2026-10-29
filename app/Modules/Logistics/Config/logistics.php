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

    // WhatsApp notifications (Meta Cloud API, via the wCRM credentials).
    // Keep the token in .env, never in the repo.
    'whatsapp' => [
        'enabled'           => env('WHATSAPP_ENABLED', false),
        'token'             => env('WHATSAPP_TOKEN', ''),
        'phone_number_id'   => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'api_version'       => env('WHATSAPP_API_VERSION', 'v23.0'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        // Also send the SMS even when the WhatsApp message went through.
        'also_sms'          => env('WHATSAPP_ALSO_SMS', false),
        // 'notice' = one flexible template for every message; 'structured' = the trip-specific templates.
        'mode'              => env('WHATSAPP_MODE', 'notice'),
        'templates'         => [
            'notice'          => env('WHATSAPP_TEMPLATE_NOTICE', 'wng_notice'),
            'driver_assigned' => env('WHATSAPP_TEMPLATE_DRIVER_ASSIGNED', 'wng_trip_driver_assigned'),
            'update'          => env('WHATSAPP_TEMPLATE_UPDATE', 'wng_trip_update'),
        ],
    ],
];
