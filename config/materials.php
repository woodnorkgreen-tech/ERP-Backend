<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Element Types
    |--------------------------------------------------------------------------
    |
    | Available element types for project materials
    |
    */
    'element_types' => [
        'stage',
        'backdrop',
        'skirting',
        'flooring',
        'trussing',
        'décor',
        'lighting',
        'sound',
        'chairs',
        'tables',
        'signage',
        'custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Material Categories
    |--------------------------------------------------------------------------
    |
    | Categories for sourcing classification
    |
    */
    'categories' => [
        'production',
        'hire',
        'outsourced',
    ],

    /*
    |--------------------------------------------------------------------------
    | Units of Measurement
    |--------------------------------------------------------------------------
    |
    | Standard units for measuring materials
    |
    */
    'units' => [
        'Pcs',      // Pieces
        'Ltrs',     // Liters
        'Mtrs',     // Meters
        'sqm',      // Square Meters
        'Pks',      // Packets
        'Kgs',      // Kilograms
        'custom',   // Custom Unit
    ],

    /*
    |--------------------------------------------------------------------------
    | Included Options
    |--------------------------------------------------------------------------
    |
    | YES/NO options for included field
    |
    */
    'included_options' => [
        'YES',
        'NO',
    ],
];
