<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Materials Library Module Configuration
    |--------------------------------------------------------------------------
    */

    'name' => 'Materials Library',
    'version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Excel Import/Export Settings
    |--------------------------------------------------------------------------
    */
    'excel' => [
        'max_file_size' => 5120, // KB (5MB)
        'allowed_extensions' => ['xlsx', 'xls'],
        'batch_size' => 500, // Number of rows to process at once
        'template_path' => storage_path('app/templates/materials-library'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'per_page' => 50,
        'max_per_page' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Settings
    |--------------------------------------------------------------------------
    */
    'search' => [
        'min_characters' => 2,
        'max_results' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workstations
    |--------------------------------------------------------------------------
    */
    'workstations' => [
        'CNC' => 'CNC Router Workstation',
        'LASER' => 'Laser Cutter Workstation',
        'LFP' => 'Large Format Print Workstation',
        'UV' => 'UV Flatbed Print Workstation',
        'MET' => 'Metal Fabrication & Welding',
        'CARP' => 'Carpentry & Woodwork',
        'PAINT' => 'Paint & Finishing Booth',
        'LED' => 'Electrical & LED Signage',
        'GEN' => 'General Hardware & Packaging',
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard Column Mapping
    |--------------------------------------------------------------------------
    | Columns that map to database fields (not JSON attributes)
    */
    'standard_columns' => [
        'SKU Code' => 'material_code',
        'Material Code' => 'material_code',
        'Material / Item Name' => 'material_name',
        'Material Name' => 'material_name',
        'Category' => 'category',
        'Sub-Category' => 'subcategory',
        'Subcategory' => 'subcategory',
        'UOM' => 'unit_of_measure',
        'Unit of Measure' => 'unit_of_measure',
        'Unit Cost' => 'unit_cost',
        'Cost per Sqm' => 'unit_cost',
        'Cost per Unit' => 'unit_cost',
        'Notes' => 'notes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */
    'permissions' => [
        'view' => 'materials-library.view',
        'create' => 'materials-library.create',
        'edit' => 'materials-library.edit',
        'delete' => 'materials-library.delete',
        'import' => 'materials-library.import',
        'export' => 'materials-library.export',
    ],
];
